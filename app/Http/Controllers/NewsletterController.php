<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSmsWalletAutoRechargeJob;
use App\Jobs\SendNewsletterJob;
use App\Models\EmailPackage;
use App\Models\Newsletter;
use App\Models\NewsletterEmail;
use App\Models\NewsletterRecipient;
use App\Models\NewsletterTemplate;
use App\Models\Organization;
use App\Models\SmsPackage;
use App\Models\User;
use App\Services\BelievePointsPaymentMethodSyncService;
use App\Services\NewsletterAiHtmlSanitizer;
use App\Services\OpenAiService;
use App\Services\TimezoneService;
use App\Support\StripeCustomerChargeAmount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

class NewsletterController extends BaseController
{
    /**
     * Merge variables allowed in AI-generated templates (must match the template editor / preview).
     *
     * @var list<string>
     */
    /** GSM single-segment style limit for SMS plain body (user-facing + AI). */
    private const NEWSLETTER_SMS_PLAIN_MAX_CHARS = 160;

    /** Verified users per scroll “page” on advanced newsletter targeting pickers. */
    private const NEWSLETTER_ADVANCED_USERS_PER_PAGE = 40;

    private const NEWSLETTER_TEMPLATE_MERGE_KEYS = [
        'organization_name',
        'organization_email',
        'organization_phone',
        'organization_address',
        'recipient_name',
        'recipient_email',
        'current_date',
        'current_year',
        'unsubscribe_link',
        'public_view_link',
    ];

    /**
     * @return list<string>
     */
    private function newsletterTemplateMergeKeys(): array
    {
        return self::NEWSLETTER_TEMPLATE_MERGE_KEYS;
    }

    private function newsletterTemplateMergeVarsPromptLine(): string
    {
        return implode(', ', array_map(fn (string $k) => '{'.$k.'}', self::NEWSLETTER_TEMPLATE_MERGE_KEYS));
    }

    /**
     * Rules for AI-generated plain text: professional tone, structure, length — not cramped “SMS-only” microcopy.
     */
    /**
     * AI rules when the campaign is SMS-only: one short GSM-style segment for "content".
     */
    private function newsletterAiSmsPlainCopyQualityRules(): string
    {
        $n = self::NEWSLETTER_SMS_PLAIN_MAX_CHARS;

        return <<<RULES
SMS PLAIN TEXT (strict length):
- The "content" field MUST be at most {$n} characters total (count spaces and line breaks; use mb-safe length mentally). This is the entire SMS body subscribers see (aside from the subject line sent above it in the app).
- Write one tight message: usually one or two short sentences, or one sentence plus a line with {unsubscribe_link} if it still fits within {$n} characters. No long newsletters.
- Put the key idea in the first sentence. Merge tokens like {unsubscribe_link} count toward the limit in the final editor—keep the draft short enough that expanded URLs still work for your use case.
- Subject: keep it short and punchy (aim under 72 characters); it appears above the body in the SMS preview.
RULES;
    }

    private function newsletterAiPlainCopyQualityRules(): string
    {
        return <<<'RULES'
PROFESSIONAL PLAIN-TEXT COPY (not tiny, not shallow):
- Write like a senior nonprofit communications lead: warm, confident, clear, donor-appropriate. No filler, no robotic stock phrases.
- Structure: optional short kicker line; a strong headline line; 3–7 paragraphs separated by double \n; optional short bullet lines using "• " or "- "; a clear call-to-action; closing line; footer with Unsubscribe using exactly {unsubscribe_link} (and {public_view_link} only where a browser link makes sense).
- Length: unless the user brief is intentionally very short, aim for roughly 180–500 words for newsletters and substantive announcements—never a single cramped paragraph or a list of tiny one-line fragments only.
- Spacing: use double line breaks between sections so the text breathes; avoid walls of dense micro-sentences.
- Voice: varied sentence length; concrete details from the brief; professional vocabulary without being stiff.
RULES;
    }

    /**
     * Hard clamp for SMS plain body (AI output or pasted text).
     */
    private function clampNewsletterSmsPlainBody(string $content): string
    {
        $max = self::NEWSLETTER_SMS_PLAIN_MAX_CHARS;
        $content = trim($content);
        if ($content === '' || mb_strlen($content) <= $max) {
            return $content;
        }

        $trunc = mb_substr($content, 0, $max);
        $lastSpace = mb_strrpos($trunc, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($max * 0.5)) {
            $trunc = mb_substr($trunc, 0, $lastSpace);
        }

        return rtrim($trunc, " \t.,;:").'…';
    }

    /**
     * Per-tone copy guidance (subject + plain + HTML text nodes). Used for plain-only and inside HTML flows.
     */
    private function newsletterAiToneCopyGuidance(string $tone): string
    {
        return match ($tone) {
            'professional' => <<<'TCOPY'
Professional (subject, headings, body copy):
- Voice: senior nonprofit communications director — credible, structured, calm authority. Short opening line stating purpose; then evidence (impact, numbers, program facts) in clear paragraphs.
- Diction: precise, inclusive, donor-appropriate. Prefer active verbs. Avoid slang, emoji, and strings of exclamation marks.
- Structure: logical flow (context → update → ask). One primary CTA; secondary links only if the brief requires.
- Subject lines: confident and specific (not clickbait); under ~90 characters when possible.
TCOPY,
            'warm' => <<<'TCOPY'
Warm (subject, headings, body copy):
- Voice: friendly community builder — grateful, human, conversational without being sloppy. Use "we" and "you" naturally; thank readers genuinely.
- Diction: plain language, short paragraphs, occasional gentle rhetorical question. Light warmth, not saccharine.
- Structure: story-first when the brief allows (one human-scale example), then clear next step. Sign off with appreciation.
- Subject lines: inviting and personal; may include {organization_name}; avoid ALL CAPS.
TCOPY,
            'urgent' => <<<'TCOPY'
Urgent (subject, headings, body copy):
- Voice: timely and direct — respect readers' time. Lead with the deadline or consequence in the first sentence; no filler.
- Diction: strong verbs; short sentences in the opening; still respectful (never manipulative or guilt-tripping). One clear "what to do now."
- Structure: urgency block → why it matters (brief) → single decisive CTA → reassurance (tax status, link to learn more) if relevant.
- Subject lines: deadline or outcome in first half; honest urgency without spam clichés.
TCOPY,
            'celebratory' => <<<'TCOPY'
Celebratory (subject, headings, body copy):
- Voice: joyful milestone energy — proud of community and impact. Energetic but professional; toast the win, then invite continued partnership.
- Diction: vivid, upbeat word choice; short celebratory headline; optional bullet list of wins. Use exclamation points sparingly (at most one in the hero line).
- Structure: celebrate → share proof or quotes → thank supporters → CTA (give, join, share). Avoid sounding flippant about serious missions.
- Subject lines: festive and specific (what we achieved together); still readable in the inbox.
TCOPY,
            default => $this->newsletterAiToneCopyGuidance('professional'),
        };
    }

    /**
     * Per-tone HTML layout/motif guidance (visual personality on top of global design rules).
     */
    private function newsletterAiToneHtmlPersonality(string $tone): string
    {
        return match ($tone) {
            'professional' => <<<'THtml'
HTML visual personality (professional):
- Aim for "annual report / trusted institution": deep navy or charcoal header band, generous white or off-white content panels with clear padding (24–32px) and a rounded outer card (border-radius 8–12px) around the main article, one strong blue or teal CTA, hairline dividers, restrained shadow on the primary button.
- On white/off-white article panels: all paragraph copy must be explicit dark slate (#0f172a / #334155). Never apply light/white text to those cells.
- Optional: ONE narrow two-column table row ONLY for compact metrics (e.g. "Donors | 1,240") — never for long paragraphs or the main story.
- Footer: dark band with muted links; keep hierarchy obvious (H1 → section → CTA → footer).
THtml,
            'warm' => <<<'THtml'
HTML visual personality (warm):
- Aim for "community coffee chat": cream or soft peach tints, rounded corners on cards (border-radius 8–12px), terracotta or amber accent, soft borders (#e7e5e4 / warm gray).
- NEVER output only white + terracotta button + terracotta footer: add a dark espresso/navy/chocolate TOP band (full width) for org line or issue kicker, then the cream article card — otherwise it looks like a draft.
- On cream/peach panels, body text MUST be dark espresso or brown (#422006, #78350f, #1c1917) — never white or cream-colored text on cream backgrounds.
- Use a friendly subhead in a tinted callout box; CTA can be warm orange or deep amber (still WCAG contrast). Avoid cold blues as the only accent.
- First line of the message body in the card should be a clear headline (<h1> or styled title), not the same font/size as body paragraphs.
- Imagery: omit or use neutral decorative bands only—no broken img URLs.
THtml,
            'urgent' => <<<'THtml'
HTML visual personality (urgent):
- Aim for "deadline / action alert": high-contrast hero strip (near-black or deep red-brown) with white headline; thin alert bar or left border accent in red or safety orange.
- Below the hero, the explanatory body usually sits on white or #fef2f2 — there you MUST use dark text (#171717, #404040, #0f172a), never white.
- CTA must be impossible to miss (large, solid fill); optional countdown-style typography for the deadline line (plain text, not scripts).
- Keep body readable: alternate white and very light gray rows; do not reduce font size below 15px for main copy.
THtml,
            'celebratory' => <<<'THtml'
HTML visual personality (celebratory):
- Aim for "gala program / milestone card": rich plum, magenta, or royal purple bands; gold or champagne accent for dividers or eyebrow text; confetti-like spacing via generous padding (no image assets required).
- Hero: bold headline on saturated mid-tone background with white text; follow with airy white or lilac-tint section for story — story text MUST be dark (#1e1b4b, #4c1d95, #0f172a), never white on pale lilac/white.
- CTA: rounded, festive color (fuchsia or gold on deep background) but still readable. Footer can echo the hero color in a slimmer band.
THtml,
            default => $this->newsletterAiToneHtmlPersonality('professional'),
        };
    }

    /**
     * Shared HTML email design instructions for AI (inline CSS, dark + light, varied palettes — not flat all-white).
     */
    private function newsletterAiHtmlDesignInstructions(string $tone): string
    {
        $base = <<<AIHTML
EMAIL HTML DESIGN — premium, highly stylized, email-client safe:
Goal: every email must look like a designed product from a top nonprofit or brand—bold color harmony, clear hierarchy, deliberate UI. Forbidden: a flat “black text on plain white only” layout with no colored structure. Forbidden: washed-out gray-only design with no strong accent.

DARK / LIGHT & PALETTE VARIETY (mandatory):
- Include at least ONE prominent dark or rich band: e.g. header strip, hero block, mid-body feature strip, or footer with background #0f172a, #18181b, #1e1b4b, #14532d, #7f1d1d, #4c1d95, or similar deep tone, with light text (#f8fafc–#ffffff) OR high-contrast inverse. Light content areas are fine, but the design must not be “all light / all white canvas only.”
- Typical winning structure: dark outer wrapper OR dark top banner row → then a full-width LIGHT content row (#ffffff / #f8fafc) for the main story with dark text — so readers never read paragraphs on the same dark purple as the “You’re invited” strip.
- Pick ONE cohesive palette per email with 4–6 intentional colors: deep neutral + surface + saturated accent + muted secondary text + hairline borders. Rotate feel by tone: e.g. midnight + electric blue + cloud white; forest + antique gold + ivory; wine + blush + charcoal; slate + emerald CTA + cream panel—not the same blue-on-white every time.
- Ensure WCAG-ish contrast: body text on backgrounds must stay readable (no light gray #cbd5e1 on white for main copy).

CRITICAL — READABILITY (main story / light panels) — NON-NEGOTIABLE:
- Light backgrounds include: #ffffff, #fffbeb, #fff7ed, #fefce8, #f8fafc, #f1f5f9, #faf5ff, #fefefe, #fffcf5, cream, ivory, or any background lighter than ~#e2e8f0. On ALL of these, paragraph and list text MUST use DARK colors only, e.g. color:#0f172a; or color:#1e293b; or color:#334155; or color:#422006; (warm) — minimum contrast like black/dark gray on paper.
- Never use pale gray, silver, or “muted” text colors (#e5e7eb, #cbd5e1, #94a3b8, #9ca3af) for body copy on white/cream — they look invisible; use #0f172a–#334155 only. Match text color to the panel: light panel = dark text; dark header/footer = light text.
- NEVER put color:#ffffff; color:#fff; color:#f8fafc; color:#e2e8f0; color:#cbd5e1; or similar light/near-white text on a light/cream/white panel — this is a common bug and is FORBIDDEN.
- White or near-white text is ONLY allowed inside genuinely DARK bands (header/footer/hero blocks with background roughly #0f172a, #18181b, #1e1b4b, #422006, #4c1d95, #7f1d1d, or similar dark hex). If you nest a light inner card inside a dark section, that inner card must switch back to dark text on light background.
- Every <p>, <li>, and main body <td> on a light background must set an explicit dark color in its style= attribute (do not assume inheritance from a wrapper).
- Links on light backgrounds: use color:#1d4ed8; or color:#0369a1; and underline — not white.

CRITICAL — DARK BACKGROUNDS (purple header, navy, charcoal strips) — NON-NEGOTIABLE:
- “Dark” includes: #0f172a, #18181b, #1e1b4b, #312e81, #4c1d95, #581c87, #5b21b6, saturated purple/magenta hero bars, deep plum, navy, #171717, or any background roughly darker than #475569.
- On ANY dark background, paragraph/list text MUST be LIGHT: color:#f8fafc; color:#ffffff; or color:#e5e7eb; — NEVER dark slate (#334155, #1e293b, #0f172a, #475569, #64748b) on dark purple/navy — that combination is unreadable.
- FORBIDDEN: placing the main invitation/body paragraphs in the same full-width dark cell as a headline band while still using dark gray “body” colors — that yields invisible text.

STRUCTURE — MAIN STORY MUST BE READABLE (pick one pattern and stick to it):
- Preferred: After a colored title/hero row, use a NEW table row whose cell has background:#ffffff; or #f8fafc; or #fffbeb; padding:24px 28px; and put ALL multi-paragraph event details there with explicit dark text colors (as in the light-panel rules above).
- Alternative: an all-dark email body — then every line of story text on dark bg must use light-colored inline styles on each <p> and <li>.
- Never leave long body copy in a dark purple/navy area with inherited or default dark-gray paragraph colors.

PROFESSIONAL LAYOUT (raise quality):
- Main content area: generous padding (24–32px) on the primary text cell; clear separation between header band, article body, and footer.
- Prefer a single centered “card” feel for the article: optional subtle border 1px solid #e2e8f0 or very soft shadow on the main white/cream block.
- Use consistent vertical rhythm: margin between paragraphs (e.g. 0 0 16px 0), one clear H1-style headline for the message, optional subhead in slightly muted dark (#475569) on light backgrounds only.

PADDING, ROUNDED CORNERS & BREATHING ROOM (MANDATORY — not optional “nice to have”):
- Do NOT output flat, edge-to-edge text blocks that touch the 600px gutters. Inner content MUST have horizontal inset: use at least padding:24px 28px on story cells (28px–36px is better for the main card).
- The main light “article card” <td> MUST look designed: combine border-radius:12px–16px; overflow:hidden; with background #ffffff or #f8fafc, plus either border:1px solid #e2e8f0; and/or box-shadow:0 10px 40px rgba(15,23,42,0.06); so the body feels like a rounded card, not a raw rectangle.
- Hero/header bands: use padding:20px 28px minimum on those cells so headlines never hug the viewport edge; rounded top corners (border-radius:12px 12px 0 0) on the hero row pair well with a rounded card row below.
- Between major sections, add clear separation: extra padding-bottom on the section cell (e.g. 20px–28px) OR a spacer row (<tr><td style="height:16px;font-size:0;line-height:0;">&nbsp;</td></tr>) — sections must not look glued together.
- Quote / highlight / event-detail sub-panels: inner padding 16px–22px; border-radius:8px–12px; subtle border or left accent border as already described.
- CTA area: wrap the button in a <td> with padding:16px 24px 24px; button uses border-radius:9999px or 10px–12px (pill look), not a sharp square.
- Footer band: padding:20px 28px; maintain rounded bottom corners (border-radius:0 0 12px 12px) if it sits under a rounded card stack for one cohesive “device” silhouette.

BODY LAYOUT — SINGLE READABLE COLUMN (NON-NEGOTIABLE):
- The greeting ("Dear {recipient_name},") and ALL narrative paragraphs MUST sit in ONE full-width vertical column inside the ~600px content area — like a real marketing email from Mailchimp or Constant Contact.
- FORBIDDEN: CSS multi-column layouts (never use column-count, columns:, or multi-column in style=). FORBIDDEN: newspaper-style narrow strips of text. FORBIDDEN: one table row with many side-by-side <td> cells each holding a slice of the same article (that produces unreadable 5–6 column disasters).
- FORBIDDEN: splitting the main story across more than one horizontal cell. The main article uses a single <td width="100%" style="..."> (or one inner table with one content column) for all body <p> tags in reading order.
- OK: optional side-by-side cells ONLY for short label/value pairs, stats digits, or a two-button row — not for the primary letter copy.

PROFESSIONAL EMAIL = TABLE-BASED “2003 WEB” (industry standard — same as Mailchimp / Campaign Monitor docs):
- Email clients (especially Outlook) do NOT reliably support CSS Grid, Flexbox for layout, or float-based columns. Your layout MUST be nested <table role="presentation"> with cellpadding="0" cellspacing="0" border="0".
- FORBIDDEN in html_content for structure: display:flex; display:grid; float:left/right for the main story; <div> “columns” that simulate a newspaper. If you need two pieces of info side by side, use ONE <tr> with exactly two <td width="50%"> ONLY for short label/value or icons — never for flowing article paragraphs.
- Center the canvas: outer wrapper <table width="100%"><tr><td align="center"> then inner <table width="600" style="max-width:600px;width:100%;"> … </table></td></tr></table>.
- Build the letter as VERTICAL STACK of full-width rows: each narrative block is <tr><td width="600" style="padding:…"> … </td></tr> with only ONE <td> in that row. Put every greeting + body <p> inside that same full-width cell (or stack multiple <tr> each with a single <td> — still one column).

CANONICAL SHAPE (your HTML must follow this stacking idea — adapt colors/sections to the brief):
1) [Optional] One row: thin accent bar (full width inside 600px table).
2) One row: hero/header band — single <td width="600">, dark bg, light text, headline only (short).
3) One row: MAIN ARTICLE — single <td width="600" style="background:#ffffff;padding:28px 32px;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 10px 40px rgba(15,23,42,0.07);"> containing ONLY: greeting line + all <p> paragraphs + lists + CTA button row + closing. All body text color:#0f172a or similar DARK on white. (Adapt padding/radius to tone but keep the card-like finish.)
4) Optional rows: quote box, event details mini-table (label | value), secondary CTA — each as its own <tr><td> full width.
5) One row: footer band — single <td>, often dark bg, small light gray links.

HEADER / HERO TEXT ON DARK BANDS:
- Any headline, title, or eyebrow line sitting on a dark or saturated background (#1e1b4b, #4c1d95, #5b21b6, #0f172a, navy, charcoal, etc.) MUST set inline text color to light: e.g. style="color:#ffffff;" or color:#f8fafc; on that element (and on nested <span> if used). Never rely on browser default black/dark gray on purple — that is unreadable.

Technical rules (must follow):
- Use ONLY inline CSS (style attributes on elements). No <style> blocks, no external stylesheets, no @import, no <script>, no web fonts from URLs.
- Quoting: use double quotes for style= attributes whenever the CSS value contains single-quoted font names, e.g. style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; ..." — never break HTML with mismatched quotes.
- Layout: outer wrapper table role="presentation" width="100%"; inner main column width="600" (HTML attribute) with style max-width:600px;width:100%; margin:0 auto; align="center" on wrapper where helpful. Stack sections as separate table rows with ONE content cell per row for the newsletter story — this is how production email HTML is written.
- Every <table> used for layout: role="presentation", border="0", cellpadding="0", cellspacing="0".

Reference palettes (adapt—do not copy blindly every time; vary with the brief and tone "{$tone}"):
  • Professional / trust: charcoal #0f172a, body #334155, accent #2563eb or #0369a1, soft panels #f1f5f9 / #eff6ff, white cards #ffffff, optional dark footer band #0f172a.
  • Warm / community: espresso #422006, body #78350f, accent #c2410c or #b45309, cream #fffbeb, peach tint #fff7ed.
  • Urgent / timely: near-black #171717, body #404040, accent #dc2626 or #ea580c, pale alert #fef2f2.
  • Celebratory: plum #5b21b6, accent #c026d3 or #db2777, gold #ca8a04, lilac panel #faf5ff.

Visual styling (apply several—not minimal):
- Thin colored top accent bar (4–6px) full width in primary accent.
- Eyebrow / kicker: small uppercase, letter-spaced, accent color (e.g. font-size:11px; letter-spacing:0.12em; font-weight:600).
- Main headline: 22–28px, extra-bold, strong contrast against its background.
- Alternate section backgrounds: white vs subtle tinted rows (#f8fafc, #f1f5f9, or 4–8% accent tint).
- Pull quote or highlight: left border 4px solid accent; tinted background; padding 16–20px.
- Primary CTA: inline-block; padding:14px 32px; strong accent background; color:#ffffff; border-radius:8px; font-weight:700; optional box-shadow:0 4px 14px rgba(0,0,0,0.12). href="#" or allowed merge tokens only.
- Dividers: 1px solid muted (#e2e8f0 or from palette).
- Footer: distinct band—often dark (#0f172a / #18181b) with muted text #94a3b8, {organization_name}, <a href="{unsubscribe_link}" style="color:#cbd5e1;">Unsubscribe</a>; optional <a href="{public_view_link}">View in browser</a>.

Typography (inline): font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; body 15–17px, line-height 1.65–1.7; headings clearly larger and colored—not browser defaults.

UNACCEPTABLE “DRAFT” LOOK (this exact failure pattern is FORBIDDEN):
- A gray outer area + one plain white content column + long body as a stack of same-weight <p> paragraphs + ONE centered accent button + matching accent footer — with NO dark/navy header band, NO real headline hierarchy, NO card shadow, NO eyebrow/kicker, NO mid-body divider or highlight strip. That reads as unfinished and is NOT professional enough for this product.
- If you use a warm terracotta/coral/orange accent for CTA + footer, you MUST still add: (1) a distinct dark or deep-colored TOP header row (org name / issue line) OR a saturated hero band above the article; (2) an <h1> or visually dominant title inside the article card (font-size 24–28px; font-weight:700; margin 0 0 12px 0) — the first line of the story must NOT be a plain <p> that looks identical to the rest; (3) at least one structural separator: horizontal rule row, “Impact at a glance” tinted strip, or left-border quote box between sections.
- The primary CTA button must sit in its own <tr><td> with padding-bottom AT LEAST 24px BEFORE the next paragraph (“Warm regards…” / closing). Never let the closing paragraphs butt against the button — that causes overlap/tight layout in clients.

MINIMAL / “3 ROW” LAYOUT IS FORBIDDEN — premium marketing email required:
- The output must NOT look like only: (1) thin header bar (2) one big plain text block (3) footer. That is unacceptable for this product.
- You MUST ship a polished layout comparable to top Mailchimp / nonprofit campaign templates: layered sections, clear hierarchy, obvious CTA, and visual “chrome” (borders, rounded panels, spacing).
- Mandatory elements (include ALL that apply to the brief):
  • A thin top accent stripe (4–6px) OR gradient-look via two adjacent table rows if needed.
  • A hero/title band (can be colored) with large headline + optional small eyebrow/kicker line above it (uppercase, letter-spaced).
  • A main content “card” on light bg with border-radius:10px–14px; padding:28px–36px; optional border:1px solid #e2e8f0; optional box-shadow:0 10px 40px rgba(15,23,42,0.08).
  • For events/invitations: a dedicated “Event details” sub-panel — use a nested table or bordered inner box with rows like Label | Value (bold label column, regular value), not a single wall of paragraphs.
  • At least ONE prominent primary CTA button (not a plain link): pill/rounded, padding 14–18px, strong fill color, white label text, centered or left-aligned in its own padded row.
  • Optional secondary text link row (“View in browser”, etc.) using {public_view_link} where appropriate.
  • A mid-body highlight strip OR quote box (left border + tinted bg) for one key sentence if it fits the brief.
  • Footer band with org context + unsubscribe — visually distinct from body (often dark).
- Spacing: never cram; use padding-bottom on sections (16–28px). Headline margin-bottom 8–12px; paragraph margin 0 0 14px 0.
- Visual interest: vary font sizes (eyebrow 11px, H1 24–30px, body 15–16px, small print 13px). Use at least two font-weight steps (600 vs 400).
- Length: html_content should be substantial HTML (multiple nested tables/rows reflecting sections), not a 10-line stub.

Images: omit <img> unless the brief asks; never broken URLs.
AIHTML;

        return $base."\n\n".$this->newsletterAiToneCopyGuidance($tone)."\n\n".$this->newsletterAiToneHtmlPersonality($tone);
    }

    /**
     * Newsletter AI uses higher temperature + optional model so HTML layouts are not overly plain.
     *
     * @return array{content: string, total_tokens: int, finish_reason: ?string}
     */
    private function newsletterAiChatCompletionJson(OpenAiService $openAiService, array $messages): array
    {
        $model = config('services.newsletter_ai.model');
        $temperature = (float) config('services.newsletter_ai.temperature', 0.74);
        $maxOut = (int) config('services.newsletter_ai.max_output_tokens', 4096);

        return $openAiService->chatCompletionJson(
            $messages,
            is_string($model) && $model !== '' ? $model : null,
            $temperature,
            $maxOut > 0 ? $maxOut : null
        );
    }

    /**
     * Map OpenAI/network exceptions to a helpful user message (avoid blaming API key for every failure).
     */
    private function newsletterAiUserFacingMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);

        if (preg_match('/OpenAI API Error:\s*(\d+)/', $msg, $m)) {
            $status = (int) $m[1];
            if ($status === 401) {
                return 'The AI service rejected authentication (HTTP 401). Verify OPENAI_API_KEY in the server environment, then run php artisan config:clear if you use config caching.';
            }
            if ($status === 429) {
                return 'The AI service rate-limited this request. Wait a minute and try again.';
            }
            if ($status === 400) {
                if (str_contains($lower, 'max_tokens') || str_contains($lower, 'too large') || str_contains($lower, 'greater than')) {
                    return 'The AI request was rejected: output token limit may be too high for this model. Set NEWSLETTER_AI_MAX_TOKENS=4096 (or lower) in .env. Details were logged.';
                }
                if (str_contains($lower, 'model') && (str_contains($lower, 'not found') || str_contains($lower, 'does not exist') || str_contains($lower, 'invalid'))) {
                    return 'The configured AI model name is not valid. Unset NEWSLETTER_AI_MODEL to use the default (gpt-4o-mini), or set a valid model id. Details were logged.';
                }

                return 'The AI service rejected the request (HTTP 400). This is often a bad model name or parameters — not necessarily your API key. Check server logs for the exact OpenAI message.';
            }
            if ($status === 404) {
                return 'The AI model was not found (HTTP 404). Check NEWSLETTER_AI_MODEL or remove it to use the default (gpt-4o-mini).';
            }
            if ($status >= 500) {
                return 'OpenAI had a temporary error. Try again in a moment.';
            }

            return 'The AI service returned an error (HTTP '.$status.'). Try again; see server logs for details.';
        }

        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'curl error')) {
            return 'Could not reach the AI service (network or SSL). For local dev, OPENAI_VERIFY_SSL=false may help; details were logged.';
        }

        if (str_contains($lower, 'empty response')) {
            return 'The AI returned an empty response. Try again with a shorter brief.';
        }

        return 'Could not generate with AI right now. Please try again. The exact error was logged for administrators.';
    }

    /**
     * Ensure AI output uses only whitelisted {name} placeholders (no Handlebars, no invented keys).
     */
    private function validateTemplateAiMergeVariablesOnly(string $subject, string $content, string $htmlContent, string $suggestedName = ''): ?string
    {
        $allowed = $this->newsletterTemplateMergeKeys();
        $blob = $subject."\n".$content."\n".$htmlContent."\n".$suggestedName;

        if (str_contains($blob, '{{') || str_contains($blob, '}}')) {
            return 'The draft used unsupported placeholder syntax (double braces). Only these merge variables are allowed: '.$this->newsletterTemplateMergeVarsPromptLine();
        }

        if (preg_match_all('/\{([^{}]*)\}/', $blob, $matches)) {
            foreach ($matches[1] as $inner) {
                $key = trim((string) $inner);
                if ($key === '') {
                    return 'The draft contained empty {} placeholders. Use only: '.$this->newsletterTemplateMergeVarsPromptLine();
                }
                if (! in_array($key, $allowed, true)) {
                    return 'The draft used a merge variable that is not supported: {'.$key.'}. Allowed variables only: '.$this->newsletterTemplateMergeVarsPromptLine();
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function templateAiStringFromDecoded(array $decoded, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $decoded)) {
                continue;
            }
            $value = $decoded[$key];
            if (is_string($value) || is_numeric($value)) {
                $trimmed = trim((string) $value);

                return $trimmed;
            }
        }

        return '';
    }

    private function newsletterAiPlainTextFromHtml(string $html): string
    {
        $withBreaks = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n\n", $html) ?? $html;
        $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function newsletterAiSimpleHtmlFromPlain(string $subject, string $content): string
    {
        $escapedSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R{2,}/', $content) ?: []
        )));
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $body .= '<p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#1e293b;">'
                .nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                .'</p>';
        }
        if ($body === '') {
            $body = '<p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#1e293b;">'
                .nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                .'</p>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;"><tr><td style="padding:32px 28px;"><h1 style="margin:0 0 16px 0;font-size:26px;line-height:1.3;color:#1e293b;">'
            .$escapedSubject
            .'</h1>'
            .$body
            .'</td></tr></table>';
    }

    private function newsletterAiSuggestedNameFromSubject(string $subject): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);
        if ($name === '') {
            return '';
        }

        return mb_substr($name, 0, 80);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} subject, content, htmlContent, suggestedName
     */
    private function repairTemplateAiPayload(
        string $subject,
        string $content,
        string $htmlContent,
        string $suggestedName,
        string $outputMode
    ): array {
        if ($suggestedName === '' && $subject !== '') {
            $suggestedName = $this->newsletterAiSuggestedNameFromSubject($subject);
        }

        if ($content === '' && $htmlContent !== '' && $outputMode !== 'plain') {
            $content = $this->newsletterAiPlainTextFromHtml($htmlContent);
        }

        if ($htmlContent === '' && $content !== '' && in_array($outputMode, ['html', 'both'], true)) {
            $htmlContent = $this->newsletterAiSimpleHtmlFromPlain($subject, $content);
            $sanitizer = app(NewsletterAiHtmlSanitizer::class);
            $htmlContent = $sanitizer->fixContrastIssues($htmlContent);
            $htmlContent = $sanitizer->uniqueifyHtmlClassNames($htmlContent);
        }

        return [$subject, $content, $htmlContent, $suggestedName];
    }

    /**
     * @return list<string>
     */
    private function templateAiMissingFields(
        string $subject,
        string $content,
        string $htmlContent,
        string $suggestedName,
        string $outputMode
    ): array {
        $missing = [];
        if ($suggestedName === '') {
            $missing[] = 'suggested_name';
        }
        if ($subject === '') {
            $missing[] = 'subject';
        }
        if ($content === '') {
            $missing[] = 'content';
        }
        if (in_array($outputMode, ['html', 'both'], true) && $htmlContent === '') {
            $missing[] = 'html_content';
        }

        return $missing;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, total_tokens: int, finish_reason: ?string}
     */
    private function retryTemplateAiForMissingFields(
        OpenAiService $openAiService,
        array $messages,
        string $assistantJson,
        array $missing,
        string $outputMode,
        string $mergeVars
    ): array {
        $missingList = implode(', ', $missing);
        $modeHint = match ($outputMode) {
            'plain' => 'Return suggested_name, subject, and content. Set html_content to "".',
            'both' => 'Return suggested_name, subject, content (full plain-text body), and html_content (full HTML body).',
            default => 'Return suggested_name, subject, content (plain-text twin), and html_content (HTML body).',
        };

        $messages[] = ['role' => 'assistant', 'content' => $assistantJson];
        $messages[] = ['role' => 'user', 'content' => <<<TXT
Your previous JSON was incomplete. Missing or empty keys: {$missingList}.

Return ONE corrected JSON object with ALL required keys populated with real copy (not placeholders). {$modeHint}
Merge variables allowed: {$mergeVars}
No markdown fences.
TXT];

        return $this->newsletterAiChatCompletionJson($openAiService, $messages);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{0: string, 1: string, 2: string, 3: string} subject, content, htmlContent, suggestedName
     */
    private function normalizeTemplateAiDecodedPayload(array $decoded, string $outputMode): array
    {
        $subject = $this->templateAiStringFromDecoded($decoded, ['subject', 'Subject', 'title', 'email_subject']);
        $content = $this->templateAiStringFromDecoded($decoded, [
            'content',
            'plain_text',
            'plain_content',
            'body',
            'text',
            'sms_body',
        ]);
        $htmlContent = $this->templateAiStringFromDecoded($decoded, [
            'html_content',
            'htmlContent',
            'html',
            'html_body',
        ]);
        $suggestedName = $this->templateAiStringFromDecoded($decoded, [
            'suggested_name',
            'suggestedName',
            'template_name',
            'name',
        ]);
        if ($outputMode === 'plain') {
            $htmlContent = '';
        } elseif ($htmlContent !== '') {
            $sanitizer = app(NewsletterAiHtmlSanitizer::class);
            $htmlContent = $sanitizer->fixContrastIssues($htmlContent);
            $htmlContent = $sanitizer->uniqueifyHtmlClassNames($htmlContent);
        }

        return $this->repairTemplateAiPayload($subject, $content, $htmlContent, $suggestedName, $outputMode);
    }

    /**
     * @return array{ok: false, message: string, code: string}|null
     */
    private function templateAiPayloadIsIncomplete(
        string $subject,
        string $content,
        string $htmlContent,
        string $suggestedName,
        string $outputMode
    ): ?array {
        if ($outputMode === 'plain') {
            if ($subject === '' || $content === '' || $suggestedName === '') {
                return [
                    'ok' => false,
                    'message' => 'AI did not return a complete plain-text template (need template name, subject, and body). Try again.',
                    'code' => 'incomplete',
                ];
            }
        } elseif ($outputMode === 'both') {
            if ($subject === '' || $content === '' || $htmlContent === '' || $suggestedName === '') {
                return [
                    'ok' => false,
                    'message' => 'AI did not return a complete template (need template name, subject, SMS plain body, and HTML body). Try again.',
                    'code' => 'incomplete',
                ];
            }
        } elseif ($subject === '' || $htmlContent === '' || $suggestedName === '' || $content === '') {
            return [
                'ok' => false,
                'message' => 'AI did not return a complete HTML template (need template name, subject, plain-text body, and HTML body). Try again.',
                'code' => 'incomplete',
            ];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: string} subject, content, htmlContent
     */
    private function normalizeNewsletterCreateAiDecodedPayload(array $decoded, string $outputMode): array
    {
        $subject = $this->templateAiStringFromDecoded($decoded, ['subject', 'Subject', 'title', 'email_subject']);
        $content = $this->templateAiStringFromDecoded($decoded, [
            'content',
            'plain_text',
            'plain_content',
            'body',
            'text',
            'sms_body',
        ]);
        $htmlContent = $this->templateAiStringFromDecoded($decoded, [
            'html_content',
            'htmlContent',
            'html',
            'html_body',
        ]);
        if ($outputMode === 'plain') {
            $htmlContent = '';
        } elseif ($htmlContent !== '') {
            $sanitizer = app(NewsletterAiHtmlSanitizer::class);
            $htmlContent = $sanitizer->fixContrastIssues($htmlContent);
            $htmlContent = $sanitizer->uniqueifyHtmlClassNames($htmlContent);
        }

        [$subject, $content, $htmlContent] = array_slice(
            $this->repairTemplateAiPayload($subject, $content, $htmlContent, '', $outputMode),
            0,
            3
        );

        return [$subject, $content, $htmlContent];
    }

    /**
     * @return array{ok: false, message: string, code: string}|null
     */
    private function newsletterCreateAiPayloadIsIncomplete(
        string $subject,
        string $content,
        string $htmlContent,
        string $outputMode
    ): ?array {
        if ($outputMode === 'plain') {
            if ($subject === '' || $content === '') {
                return [
                    'ok' => false,
                    'message' => 'AI did not return a complete plain-text draft (need subject and body). Try again.',
                    'code' => 'incomplete',
                ];
            }
        } elseif ($outputMode === 'both') {
            if ($subject === '' || $content === '' || $htmlContent === '') {
                return [
                    'ok' => false,
                    'message' => 'AI did not return a complete draft (need subject, SMS plain body, and HTML body). Try again.',
                    'code' => 'incomplete',
                ];
            }
        } elseif ($subject === '' || $htmlContent === '' || $content === '') {
            return [
                'ok' => false,
                'message' => 'AI did not return a complete HTML draft (need subject, plain-text body, and HTML body). Try again.',
                'code' => 'incomplete',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $newsletterCreateAiResult
     * @return array<string, mixed>
     */
    protected function newsletterCreatePageData(?array $newsletterCreateAiResult = null, ?Request $request = null): array
    {
        $req = $request ?? request();
        $orgScope = $this->newsletterOrganizationScope($req);
        $templateQuery = NewsletterTemplate::where('is_active', true)
            ->select(['id', 'name', 'subject', 'content', 'template_type', 'html_content']);
        $this->applyNewsletterOrganizationScope($templateQuery, $orgScope);
        $templates = $templateQuery->get();

        $user = Auth::user();
        /** @var User $user */
        $org = $user instanceof User ? Organization::forAuthUser($user) : null;

        $orgAddress = '';
        if ($org) {
            $addressParts = array_filter([
                $org->street,
                $org->city,
                $org->state,
                $org->zip,
            ]);
            $orgAddress = ! empty($addressParts) ? implode(', ', $addressParts) : 'Your Organization Address';
        }

        $publicViewLink = url('/newsletter/public/preview');

        $previewData = [
            'organization_name' => $org?->name ?? ($user->name ?? 'Your Organization'),
            'organization_email' => $org?->email ?? ($user->email ?? 'support@believeinunity.org'),
            'organization_phone' => $org?->phone ?? ($user->contact_number ?? '+1 (555) 000-0000'),
            'organization_address' => $orgAddress ?: 'Your Organization Address',
            'recipient_name' => $user->name ?? 'Recipient Name',
            'recipient_email' => $user->email ?? 'recipient@example.com',
            'current_date' => Carbon::now()->format('F j, Y'),
            'current_year' => (string) Carbon::now()->year,
            'unsubscribe_link' => url('/newsletter/unsubscribe?token=preview_token'),
            'public_view_link' => $publicViewLink,
        ];

        $allowedAudienceSegments = ['followers', 'donors', 'volunteers', 'newsletter_contacts'];
        $qSegment = $request?->query('audience_segment');
        $loadSegment = is_string($qSegment) && in_array($qSegment, $allowedAudienceSegments, true) ? $qSegment : null;

        $newsletterAudienceCounts = $org
            ? $org->newsletterAudienceCounts()
            : [
                'followers' => 0,
                'donors' => 0,
                'volunteers' => 0,
                'newsletter_contacts' => 0,
            ];

        $newsletterAudiencePreview = [];
        $newsletterAudienceLoadedSegment = null;
        if ($org && $loadSegment) {
            $newsletterAudiencePreview = $org->newsletterAudienceDetailForSegment($loadSegment, 500);
            $newsletterAudienceLoadedSegment = $loadSegment;
        }

        $newsletterRecipientCount = (int) ($newsletterAudienceCounts['newsletter_contacts'] ?? 0);
        $newsletterRecipientPreview = [];
        if ($loadSegment === 'newsletter_contacts' && $newsletterAudiencePreview !== []) {
            foreach ($newsletterAudiencePreview as $row) {
                if (($row['kind'] ?? '') === 'contact') {
                    $newsletterRecipientPreview[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'name' => (string) ($row['name'] ?? ''),
                        'email' => (string) ($row['email'] ?? ''),
                    ];
                }
            }
        }

        return [
            'templates' => $templates,
            'previewData' => $previewData,
            'openAiConfigured' => $this->openAiApiKeyIsConfigured(),
            'newsletterCreateAiResult' => $newsletterCreateAiResult,
            'newsletterAudienceCounts' => $newsletterAudienceCounts,
            'newsletterAudiencePreview' => $newsletterAudiencePreview,
            'newsletterAudienceLoadedSegment' => $newsletterAudienceLoadedSegment,
            'newsletterRecipientCount' => $newsletterRecipientCount,
            'newsletterRecipientPreview' => $newsletterRecipientPreview,
            'canUseNewsletterProTargeting' => $user instanceof User && $this->userCanUseNewsletterProTargeting($user),
            'newsletterRecipientInlineNotice' => null,
        ];
    }

    /**
     * Inertia 200 response for newsletter create after inline recipient actions — no redirect, correct page URL.
     *
     * @param  array<string, mixed>  $extraPageProps
     */
    protected function inertiaNewsletterCreateAudience(Request $request, array $extraPageProps = []): Response
    {
        $createPath = route('newsletter.create', ['audience_segment' => 'newsletter_contacts'], false);
        $getRequest = Request::create($createPath, 'GET');

        /** @var \Inertia\ResponseFactory $factory */
        $factory = app(\Inertia\ResponseFactory::class);

        $props = array_merge(
            $factory->getShared(),
            $this->newsletterCreatePageData(null, $getRequest),
            $extraPageProps
        );

        return new Response(
            'newsletter/create',
            $props,
            config('inertia.root_view', 'app'),
            $factory->getVersion(),
            (bool) config('inertia.history.encrypt', false),
            fn (): string => $createPath
        );
    }

    /**
     * Email invite quota + prepaid SMS wallet (same model as /email-invite: included − used = remaining).
     *
     * @return array{emailStats: array{emails_included: int, emails_used: int, emails_left: int}, emailPackages: array<int, array<string, mixed>>, smsStats: array{sms_included: int, sms_used: int, sms_left: int}, smsPackages: array<int, array<string, mixed>>, smsAutoRechargeEnabled: bool}
     */
    protected function newsletterSmsWalletProps(): array
    {
        $user = Auth::user();
        $emailsIncluded = (int) ($user->emails_included ?? 0);
        $emailsUsed = (int) ($user->emails_used ?? 0);
        $emailsLeft = max(0, $emailsIncluded - $emailsUsed);

        $emailPackages = EmailPackage::active()->ordered()->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'emails_count' => $p->emails_count,
                'price' => (float) $p->price,
            ];
        });

        $included = (int) ($user->sms_included ?? 0);
        $used = (int) ($user->sms_used ?? 0);
        $left = max(0, $included - $used);

        $packages = SmsPackage::active()->ordered()->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'sms_count' => $p->sms_count,
                'price' => (float) $p->price,
            ];
        });

        return [
            'emailStats' => [
                'emails_included' => $emailsIncluded,
                'emails_used' => $emailsUsed,
                'emails_left' => $emailsLeft,
            ],
            'emailPackages' => $emailPackages,
            'smsStats' => [
                'sms_included' => $included,
                'sms_used' => $used,
                'sms_left' => $left,
            ],
            'smsPackages' => $packages,
            'smsAutoRechargeEnabled' => (bool) ($user->sms_auto_recharge_enabled ?? false),
            'smsAutoRecharge' => [
                'threshold' => $user->sms_auto_recharge_threshold !== null ? (int) $user->sms_auto_recharge_threshold : null,
                'package_id' => $user->sms_auto_recharge_package_id !== null ? (int) $user->sms_auto_recharge_package_id : null,
                'has_payment_method' => filled($user->sms_auto_recharge_pm_id ?? null),
                'card_brand' => $user->sms_auto_recharge_card_brand,
                'card_last4' => $user->sms_auto_recharge_card_last4,
                'last_recharge_at' => $user->sms_last_auto_recharge_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Organizations shown on the newsletter recipients list: approved registration only,
     * excluding internal Care Alliance hub rows (same notion of "real" orgs as elsewhere in the app).
     */
    protected function newsletterRecipientsOrganizationsQuery(): Builder
    {
        return Organization::query()
            ->active()
            ->excludingCareAllianceHubs()
            ->with(['user', 'newsletterRecipients']);
    }

    /**
     * Organization IDs the authenticated user may access for newsletters and templates.
     * Admins: unrestricted (returns null; callers skip org filters).
     *
     * @return array<int>|null null = no org filter (admin); empty array = user has no org access
     */
    protected function newsletterOrganizationScope(Request $request): ?array
    {
        if ($this->isAdmin($request)) {
            return null;
        }

        $user = $request->user();
        if (! $user) {
            return [];
        }

        $ids = collect();

        $ids = $ids->merge(Organization::query()->where('user_id', $user->id)->pluck('id'));
        $ids = $ids->merge($user->boardMemberships()->pluck('organization_id'));

        $authOrg = Organization::forAuthUser($user);
        if ($authOrg) {
            $ids->push($authOrg->id);
        }

        return $ids->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Limit queries on models that use {@see Newsletter::$organization_id} / {@see NewsletterTemplate::$organization_id}.
     */
    protected function applyNewsletterOrganizationScope(Builder $query, ?array $orgScope): void
    {
        if ($orgScope === null) {
            return;
        }
        if ($orgScope === []) {
            $query->whereRaw('0 = 1');

            return;
        }
        $query->whereIn('organization_id', $orgScope);
    }

    protected function assertNewsletterAccessible(Request $request, Newsletter $newsletter): void
    {
        $scope = $this->newsletterOrganizationScope($request);
        if ($scope === null) {
            return;
        }
        $oid = $newsletter->organization_id;
        if ($oid === null || ! in_array((int) $oid, $scope, true)) {
            abort(403);
        }
    }

    protected function assertNewsletterTemplateAccessible(Request $request, NewsletterTemplate $template): void
    {
        $scope = $this->newsletterOrganizationScope($request);
        if ($scope === null) {
            return;
        }
        $oid = $template->organization_id;
        if ($oid === null || ! in_array((int) $oid, $scope, true)) {
            abort(403);
        }
    }

    /**
     * Nonprofits listed on /newsletter/recipients must belong to the authenticated user's accessible orgs.
     */
    protected function applyRecipientsOrganizationsScope(Builder $organizationsQuery, ?array $orgScope): void
    {
        if ($orgScope === null) {
            return;
        }
        if ($orgScope === []) {
            $organizationsQuery->whereRaw('0 = 1');

            return;
        }
        $organizationsQuery->whereIn('id', $orgScope);
    }

    protected function assertOrganizationInUserScope(Request $request, Organization $organization): void
    {
        $scope = $this->newsletterOrganizationScope($request);
        if ($scope === null) {
            return;
        }
        if (! in_array((int) $organization->id, $scope, true)) {
            abort(403);
        }
    }

    /**
     * Display newsletter dashboard
     */
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, 'newsletter.read');

        $orgScope = $this->newsletterOrganizationScope($request);

        $query = Newsletter::with(['template', 'organization'])
            ->select([
                'id', 'subject', 'status', 'scheduled_at', 'send_date',
                'sent_at', 'schedule_type', 'total_recipients', 'sent_count',
                'delivered_count', 'opened_count', 'clicked_count',
                'newsletter_template_id', 'organization_id', 'updated_at',
            ]);
        $this->applyNewsletterOrganizationScope($query, $orgScope);

        // Apply search filter
        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'LIKE', "%{$search}%")
                    ->orWhereHas('template', function ($templateQuery) use ($search) {
                        $templateQuery->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('organization', function ($orgQuery) use ($search) {
                        $orgQuery->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Apply status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $newsletters = $query->latest()->paginate(5);

        // Format all dates - Convert from UTC (database) to user's timezone
        $userTimezone = TimezoneService::requestTimezone($request);
        $newsletters->getCollection()->transform(function ($newsletter) use ($userTimezone) {
            if ($newsletter->scheduled_at) {
                // Get raw UTC value from database - Laravel stores as UTC string
                $rawValue = $newsletter->getRawOriginal('scheduled_at') ?? $newsletter->scheduled_at;
                $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
                $newsletter->scheduled_at_formatted = $date->format('M d, Y h:i A');
                $newsletter->scheduled_at_iso = $date->toISOString();
            }
            if ($newsletter->send_date) {
                // Get raw UTC value from database
                $rawValue = $newsletter->getRawOriginal('send_date') ?? $newsletter->send_date;
                $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
                $newsletter->send_date_formatted = $date->format('M d, Y h:i A');
                $newsletter->send_date_iso = $date->toISOString();
            }
            if ($newsletter->sent_at) {
                $rawValue = $newsletter->getRawOriginal('sent_at') ?? $newsletter->sent_at;
                $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
                $newsletter->sent_at_formatted = $date->format('M d, Y h:i A');
            }
            if ($newsletter->updated_at) {
                $newsletter->updated_at_formatted = Carbon::parse($newsletter->updated_at)->setTimezone($userTimezone)->format('M d, Y');
            }

            return $newsletter;
        });

        $templateQuery = NewsletterTemplate::where('is_active', true);
        $this->applyNewsletterOrganizationScope($templateQuery, $orgScope);
        $templates = $templateQuery->get();

        $recipientsQuery = NewsletterRecipient::active();
        $this->applyNewsletterOrganizationScope($recipientsQuery, $orgScope);
        $recipients = $recipientsQuery->count();

        $sentQuery = Newsletter::where('status', 'sent');
        $this->applyNewsletterOrganizationScope($sentQuery, $orgScope);

        $stats = [
            'total_newsletters' => $newsletters->total(),
            'sent_newsletters' => $sentQuery->count(),
            'total_recipients' => $recipients,
            'avg_open_rate' => $this->getAverageOpenRate($orgScope),
            'avg_click_rate' => $this->getAverageClickRate($orgScope),
        ];

        return Inertia::render('newsletter/index', array_merge([
            'newsletters' => $newsletters,
            'templates' => $templates,
            'stats' => $stats,
            'search' => $request->input('search', ''),
            'statusFilter' => $request->input('status', 'all'),
        ], $this->newsletterSmsWalletProps()));
    }

    /**
     * Export newsletters to CSV
     */
    public function export(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.read');

        $orgScope = $this->newsletterOrganizationScope($request);

        $query = Newsletter::with(['template', 'organization'])
            ->select([
                'id', 'subject', 'status', 'scheduled_at', 'send_date',
                'sent_at', 'schedule_type', 'total_recipients', 'sent_count',
                'delivered_count', 'opened_count', 'clicked_count',
                'bounced_count', 'unsubscribed_count',
                'newsletter_template_id', 'organization_id', 'created_at',
            ]);
        $this->applyNewsletterOrganizationScope($query, $orgScope);

        // Apply search filter
        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'LIKE', "%{$search}%")
                    ->orWhereHas('template', function ($templateQuery) use ($search) {
                        $templateQuery->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('organization', function ($orgQuery) use ($search) {
                        $orgQuery->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Apply status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $newsletters = $query->latest()->get();

        $filename = 'newsletters_export_'.date('Y-m-d_H-i-s').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($newsletters) {
            $file = fopen('php://output', 'w');

            // CSV headers
            fputcsv($file, [
                'ID',
                'Subject',
                'Status',
                'Template',
                'Organization',
                'Total Recipients',
                'Sent Count',
                'Delivered Count',
                'Opened Count',
                'Clicked Count',
                'Bounced Count',
                'Unsubscribed Count',
                'Open Rate (%)',
                'Click Rate (%)',
                'Scheduled At',
                'Sent At',
                'Created At',
            ]);

            foreach ($newsletters as $newsletter) {
                $openRate = $newsletter->total_recipients > 0
                    ? round(($newsletter->opened_count / $newsletter->total_recipients) * 100, 2)
                    : 0;
                $clickRate = $newsletter->total_recipients > 0
                    ? round(($newsletter->clicked_count / $newsletter->total_recipients) * 100, 2)
                    : 0;

                fputcsv($file, [
                    $newsletter->id,
                    $newsletter->subject,
                    $newsletter->status,
                    $newsletter->template->name ?? 'N/A',
                    $newsletter->organization->name ?? 'N/A',
                    $newsletter->total_recipients,
                    $newsletter->sent_count,
                    $newsletter->delivered_count,
                    $newsletter->opened_count,
                    $newsletter->clicked_count,
                    $newsletter->bounced_count,
                    $newsletter->unsubscribed_count,
                    $openRate,
                    $clickRate,
                    $newsletter->scheduled_at ? $newsletter->scheduled_at->format('Y-m-d H:i:s') : 'N/A',
                    $newsletter->sent_at ? $newsletter->sent_at->format('Y-m-d H:i:s') : 'N/A',
                    $newsletter->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Show newsletter templates
     */
    public function templates(Request $request): Response
    {
        $this->authorizePermission($request, 'newsletter.read');

        $orgScope = $this->newsletterOrganizationScope($request);

        $templatesQuery = NewsletterTemplate::with('organization')
            ->select([
                'id', 'name', 'subject', 'template_type', 'is_active',
                'created_at', 'html_content', 'organization_id',
                'frequency_limit', 'custom_frequency_days', 'frequency_notes',
            ])
            ->latest();
        $this->applyNewsletterOrganizationScope($templatesQuery, $orgScope);
        $templates = $templatesQuery->paginate(10);

        return Inertia::render('newsletter/templates', [
            'templates' => $templates,
        ]);
    }

    /**
     * Show advanced newsletter creation form
     */
    public function createAdvanced(Request $request): Response
    {
        $this->authorizePermission($request, 'newsletter.create');

        $orgScope = $this->newsletterOrganizationScope($request);
        $templatesQuery = NewsletterTemplate::where('is_active', true)
            ->select([
                'id', 'name', 'subject', 'content', 'template_type', 'html_content',
                'frequency_limit', 'custom_frequency_days', 'frequency_notes',
            ]);
        $this->applyNewsletterOrganizationScope($templatesQuery, $orgScope);
        $templates = $templatesQuery->get();

        $authOrg = Organization::forAuthUser($request->user());

        // All approved active nonprofits (includes Care Alliance hubs — “Organizations” means the full directory).
        $careAllianceHubOrgIds = [];
        if (Schema::hasTable('care_alliances') && Schema::hasColumn('care_alliances', 'hub_organization_id')) {
            $careAllianceHubOrgIds = DB::table('care_alliances')
                ->whereNotNull('hub_organization_id')
                ->pluck('hub_organization_id')
                ->map(static fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }
        $careAllianceHubOrgIdFlip = array_flip($careAllianceHubOrgIds);

        $organizations = Organization::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'status', 'registration_status'])
            ->map(static function (Organization $o) use ($careAllianceHubOrgIdFlip): array {
                return [
                    'id' => $o->id,
                    'name' => $o->name,
                    'email' => $o->email,
                    'status' => $o->status,
                    'registration_status' => $o->registration_status,
                    'is_care_alliance_hub' => isset($careAllianceHubOrgIdFlip[(int) $o->id]),
                ];
            })
            ->values()
            ->all();

        $mapUserForAdvanced = static function (User $u): array {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->roles->pluck('name')->values()->all(),
                'email_verified_at' => $u->email_verified_at?->toIso8601String(),
            ];
        };

        $roles = \Spatie\Permission\Models\Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->reject(fn (string $name) => in_array($name, ['organization_pending', 'admin'], true))
            ->values()
            ->all();

        $usersPage = max(1, (int) $request->input('users_page', 1));
        $usersPaginator = User::query()
            ->with('roles')
            ->whereNotNull('email_verified_at')
            ->orderBy('name')
            ->paginate(self::NEWSLETTER_ADVANCED_USERS_PER_PAGE, ['*'], 'users_page', $usersPage);

        $usersChunk = $usersPaginator->getCollection()->map($mapUserForAdvanced)->values()->all();

        $usersMeta = [
            'current_page' => $usersPaginator->currentPage(),
            'last_page' => $usersPaginator->lastPage(),
            'per_page' => $usersPaginator->perPage(),
            'total' => $usersPaginator->total(),
        ];

        if ($authOrg) {
            $audienceScope = 'organization';
            $supportersPage = max(1, (int) $request->input('supporters_page', 1));
            $supporterPaginator = User::query()
                ->role('user')
                ->with('roles')
                ->whereNotNull('email_verified_at')
                ->orderBy('name')
                ->paginate(self::NEWSLETTER_ADVANCED_USERS_PER_PAGE, ['*'], 'supporters_page', $supportersPage);
            $supporterUsers = $supporterPaginator->getCollection()->map($mapUserForAdvanced)->values()->all();
            $supporterUsersMeta = [
                'current_page' => $supporterPaginator->currentPage(),
                'last_page' => $supporterPaginator->lastPage(),
                'per_page' => $supporterPaginator->perPage(),
                'total' => $supporterPaginator->total(),
            ];
        } else {
            $audienceScope = 'platform';
            $supporterUsers = null;
            $supporterUsersMeta = null;
        }

        $previewRolesInput = $request->input('preview_roles', []);
        if (! is_array($previewRolesInput)) {
            $previewRolesInput = [];
        }
        $previewRolesInput = array_values(array_unique(array_filter(
            $previewRolesInput,
            fn ($r) => is_string($r) && $r !== ''
        )));
        $allowedRoleFlip = array_flip($roles);
        $previewRoles = array_values(array_filter(
            $previewRolesInput,
            fn (string $name) => isset($allowedRoleFlip[$name])
        ));

        $roleMatchTotal = null;
        if ($previewRoles !== []) {
            $roleMatchTotal = User::query()
                ->whereNotNull('email_verified_at')
                ->whereHas('roles', function ($q) use ($previewRoles): void {
                    $q->whereIn('name', $previewRoles);
                })
                ->count();
        }

        $stats = [
            'verified_users' => User::query()->whereNotNull('email_verified_at')->count(),
            'supporters' => User::query()->role('user')->whereNotNull('email_verified_at')->count(),
            'role_match_total' => $roleMatchTotal,
        ];

        $user = $request->user();

        $priceUsd = (float) config('newsletter.pro_targeting_lifetime_price_usd', 49);
        $purchaseEnabled = (bool) config('newsletter.pro_targeting_purchase_enabled', true);

        return Inertia::render('newsletter/create-advanced', [
            'templates' => $templates,
            'users' => $usersChunk,
            'users_meta' => $usersMeta,
            'supporter_users' => $supporterUsers,
            'supporter_users_meta' => $supporterUsersMeta,
            'organizations' => $organizations,
            'roles' => $roles,
            'audience_scope' => $audienceScope,
            'related_supporter_count' => $authOrg ? User::query()->role('user')->whereNotNull('email_verified_at')->count() : null,
            'stats' => $stats,
            'has_newsletter_pro_targeting' => $user instanceof User && $this->userCanUseNewsletterProTargeting($user),
            'newsletter_pro_targeting_lifetime_price_usd' => round($priceUsd, 2),
            'newsletter_pro_targeting_purchase_enabled' => $purchaseEnabled && $priceUsd > 0,
        ]);
    }

    /**
     * Create new template
     */
    public function createTemplate(Request $request): Response
    {
        $this->authorizePermission($request, 'newsletter.create');

        return $this->renderNewsletterTemplateForm(null, null);
    }

    /**
     * Whether OPENAI_API_KEY is set (for UI: show AI assistant when true).
     */
    protected function openAiApiKeyIsConfigured(): bool
    {
        $key = config('services.openai.api_key');

        return is_string($key) && trim($key) !== '';
    }

    /**
     * Sample merge-tag preview data for template create/edit pages.
     */
    protected function newsletterTemplatePreviewData(): array
    {
        $user = Auth::user();
        $orgAddress = '';
        if ($user->organization) {
            $addressParts = array_filter([
                $user->organization->street,
                $user->organization->city,
                $user->organization->state,
                $user->organization->zip,
            ]);
            $orgAddress = ! empty($addressParts) ? implode(', ', $addressParts) : 'Your Organization Address';
        }

        return [
            'organization_name' => $user->organization->name ?? ($user->name ?? 'Your Organization'),
            'organization_email' => $user->organization->email ?? ($user->email ?? 'support@believeinunity.org'),
            'organization_phone' => $user->organization->phone ?? ($user->contact_number ?? '+1 (555) 000-0000'),
            'organization_address' => $orgAddress ?: 'Your Organization Address',
            'recipient_name' => $user->name ?? 'Recipient Name',
            'recipient_email' => $user->email ?? 'recipient@example.com',
            'current_date' => Carbon::now()->format('F j, Y'),
            'current_year' => (string) Carbon::now()->year,
            'unsubscribe_link' => url('/newsletter/unsubscribe?token=preview_token'),
            'public_view_link' => url('/newsletter/public/preview'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $templateAiResult
     */
    protected function renderNewsletterTemplateForm(?NewsletterTemplate $template, ?array $templateAiResult): Response
    {
        $props = [
            'previewData' => $this->newsletterTemplatePreviewData(),
            'openAiConfigured' => $this->openAiApiKeyIsConfigured(),
            'templateAiResult' => $templateAiResult,
        ];
        if ($template !== null) {
            $props['template'] = $template;
        }

        return Inertia::render('newsletter/template-form', $props);
    }

    /**
     * Generate newsletter template draft (subject, plain text, HTML) from a short brief via OpenAI.
     * Returns Inertia (same page) so the client can use router.post without fetch/axios.
     */
    public function generateTemplateWithAi(Request $request, OpenAiService $openAiService): Response
    {
        $validated = $request->validate([
            'brief' => 'required|string|max:3000',
            'template_type' => 'nullable|in:newsletter,announcement,event',
            'tone' => 'nullable|in:professional,warm,urgent,celebratory',
            'output_mode' => 'required|in:plain,html',
            'send_via' => 'nullable|in:email,sms,both,push',
            'template_id' => 'nullable|integer|exists:newsletter_templates,id',
        ]);

        $formTemplate = null;
        if (! empty($validated['template_id'])) {
            $this->authorizePermission($request, 'newsletter.edit');
            $formTemplate = NewsletterTemplate::findOrFail((int) $validated['template_id']);
            $this->assertNewsletterTemplateAccessible($request, $formTemplate);
        } else {
            $this->authorizePermission($request, 'newsletter.create');
        }

        if (! $this->openAiApiKeyIsConfigured()) {
            return $this->renderNewsletterTemplateForm($formTemplate, [
                'ok' => false,
                'message' => 'AI is not configured. Set OPENAI_API_KEY on the server.',
                'code' => 'not_configured',
            ]);
        }

        $user = Auth::user();
        $tokensIncluded = (int) ($user->ai_tokens_included ?? 0);
        $tokensUsed = (int) ($user->ai_tokens_used ?? 0);
        if ($tokensIncluded > 0 && $tokensUsed >= $tokensIncluded) {
            return $this->renderNewsletterTemplateForm($formTemplate, [
                'ok' => false,
                'message' => 'You have used all your AI tokens for this period. Upgrade your plan or wait for your next allocation.',
                'code' => 'insufficient_tokens',
            ]);
        }

        $templateType = $validated['template_type'] ?? 'newsletter';
        $tone = $validated['tone'] ?? 'professional';
        $outputMode = $validated['output_mode'];
        $sendVia = $validated['send_via'] ?? 'email';
        if ($sendVia === 'sms' || $sendVia === 'push') {
            $outputMode = 'plain';
        } elseif ($sendVia === 'both') {
            // Same fields as HTML mode (plain twin + HTML); SMS length is applied at send time.
            $outputMode = 'html';
        }

        $typeLabels = [
            'newsletter' => 'a recurring nonprofit / community newsletter',
            'announcement' => 'an important one-off announcement email',
            'event' => 'an event invitation or reminder email',
        ];
        $typeLabel = $typeLabels[$templateType] ?? $typeLabels['newsletter'];

        $mergeVars = $this->newsletterTemplateMergeVarsPromptLine();

        $plainQuality = $sendVia === 'sms'
            ? $this->newsletterAiSmsPlainCopyQualityRules()
            : $this->newsletterAiPlainCopyQualityRules();
        $htmlDesign = $this->newsletterAiHtmlDesignInstructions($tone);
        $toneCopyGuidance = $this->newsletterAiToneCopyGuidance($tone);

        if ($outputMode === 'plain') {
            $systemPrompt = <<<PROMPT
You help nonprofits write email templates. Respond with ONLY a single JSON object (no markdown fences).
Required keys: "subject" (string), "content" (string), "suggested_name" (string, 2-6 words, title case — this is the internal template name).

PLAIN TEXT OUTPUT ONLY for this request:
- Generate suggested_name, subject, and the full email body as plain text only in "content".
- Do NOT use HTML tags anywhere. Set "html_content" to exactly "" (empty string).

{$plainQuality}

MERGE VARIABLES — STRICT (non-negotiable):
- The ONLY strings that may appear inside single curly braces {…} anywhere in subject or body are exactly: {$mergeVars}
- There are NO other merge fields. Do NOT invent placeholders for stories, blurbs, CTAs, URLs, or “sections”. Forbidden examples (never output these): {impact_story}, {story}, {body}, {main_content}, {cta_link}, {donate_link}, {event_info}, {donor_name}, {first_name}, {date}, or any {word} except those listed above.
- If the brief asks for an impact story, example copy, or event details: write that text in full as normal sentences—no curly braces around it.
- Do NOT use {{name}}, %email%, [FIRST_NAME], or double curly braces. No spaces inside braces (use {organization_name} not { organization_name }).

Subject: compelling, under 200 characters; you may include {organization_name} where it fits.
content: must include the real unsubscribe merge token, e.g. a line "Unsubscribe: {unsubscribe_link}" using exactly {unsubscribe_link}.

{$toneCopyGuidance}
PROMPT;
        } elseif ($outputMode === 'both') {
            $systemPrompt = <<<PROMPT
You help nonprofits write email templates. Respond with ONLY a single JSON object (no markdown fences).
Required keys: "subject" (string), "content" (string), "html_content" (string), "suggested_name" (string, 2-6 words, title case — this is the internal template name).

DUAL OUTPUT — full professional plain text + HTML email (same campaign):

{$plainQuality}

- "content": Full text/plain MIME body: multi-paragraph, \\n\\n between sections, same narrative as the HTML—not a short SMS-only stub. Typical length when the brief allows: ~150–500+ words for newsletters. No HTML tags. Merge variables only: {$mergeVars}. Include {unsubscribe_link} (and {public_view_link} where appropriate).
- "html_content": Implement the full HTML body following the design specification below (same story and merge-variable rules).

MERGE VARIABLES — STRICT (non-negotiable) in subject, content, and html_content:
- The ONLY strings that may appear inside single curly braces {…} are exactly: {$mergeVars}
- Do NOT use {{name}}, %email%, [NAME], or double curly braces. No spaces inside braces.

{$htmlDesign}
PROMPT;
        } else {
            $systemPrompt = <<<PROMPT
You help nonprofits write email templates. Respond with ONLY a single JSON object (no markdown fences).
Required keys: "subject" (string), "html_content" (string), "content" (string), "suggested_name" (string, 2-6 words, title case — this is the internal template name).

HTML plus plain text (always include both):

{$plainQuality}

- "html_content": full email-safe HTML body implementing the design specification below.
- "content": REQUIRED full plain-text twin for email text/plain part. No HTML tags; line breaks as \\n; merge variables only: {$mergeVars}. Faithfully match the HTML (headlines, sections, CTA, footer). Include {unsubscribe_link}.

MERGE VARIABLES — STRICT (non-negotiable):
- The ONLY strings that may appear inside single curly braces {…} anywhere in subject, html_content, or content are exactly: {$mergeVars}
- There are NO other merge fields. Do NOT invent placeholders for stories, blurbs, CTAs, or URLs. Forbidden examples (never output these): {impact_story}, {story}, {body}, {main_content}, {cta_link}, {donate_link}, {event_info}, {donor_name}, {first_name}, or any {word} except those listed above.
- If the brief asks for an impact story, example copy, or event details: write that as real HTML text (paragraphs, lists)—never as a {placeholder}.
- Do NOT use {{name}}, %email%, [NAME], or double curly braces. No spaces inside braces.

Subject: compelling, under 200 characters; you may include {organization_name} where it fits.

{$htmlDesign}
PROMPT;
        }

        $modeLine = match ($outputMode) {
            'plain' => 'PLAIN TEXT ONLY (no HTML).',
            'both' => 'Full professional plain text in "content" PLUS full HTML in "html_content" (both required).',
            default => 'HTML in "html_content" AND a full plain-text twin in "content" (both required).',
        };
        $userPrompt = "Template purpose: {$typeLabel}.\nOutput mode: {$modeLine}\nDesired tone: {$tone}.\n\nBrief from the user:\n".$validated['brief'];
        if ($outputMode === 'html' || $outputMode === 'both') {
            $userPrompt .= "\n\nMandatory visual requirement: use a distinctive palette with at least one dark or richly colored header, footer, or feature band—not an all-white-only layout. Include strong accent, kicker line, primary CTA, and clear sections. Follow the HTML VISUAL PERSONALITY block for tone \"{$tone}\" exactly (layout motifs, palette feel).";
            $userPrompt .= "\n\nCRITICAL readability: (1) Light panels = dark text only. (2) Dark purple/navy/header areas = light text only—never #334155 or similar dark gray on dark backgrounds. (3) Put the main invitation/body paragraphs in a white or off-white content row below the hero strip, with padding—do not leave long dark-gray paragraphs on the same dark bg as the banner.";
            $userPrompt .= "\n\nLayout: main story must be ONE readable column (full width inside 600px)—never CSS multi-column, never many side-by-side table cells for the same letter body.";
            $userPrompt .= "\n\nDesign bar: finished premium nonprofit email — dark or deep header/hero band above the story, real <h1>-level title in the article card, eyebrow optional, rounded white/light card with shadow, generous padding, pill CTA in its own row with 24px+ space before closing/sign-off paragraphs, rich footer. FORBIDDEN: gray page + plain white text column + undifferentiated <p> wall + one orange button + matching footer with no hierarchy (draft look).";
        }
        if ($sendVia === 'sms') {
            $userPrompt .= "\n\nHard limit: \"content\" must be at most ".self::NEWSLETTER_SMS_PLAIN_MAX_CHARS.' characters (SMS segment).';
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $result = $this->newsletterAiChatCompletionJson($openAiService, $messages);
            $totalTokens = (int) ($result['total_tokens'] ?? 0);

            $decoded = json_decode($result['content'], true);
            if (! is_array($decoded)) {
                return $this->renderNewsletterTemplateForm($formTemplate, [
                    'ok' => false,
                    'message' => 'AI returned an invalid response. Please try again.',
                    'code' => 'invalid_response',
                ]);
            }

            [$subject, $content, $htmlContent, $suggestedName] = $this->normalizeTemplateAiDecodedPayload($decoded, $outputMode);
            if ($sendVia === 'sms') {
                $content = $this->clampNewsletterSmsPlainBody($content);
            }

            $incomplete = $this->templateAiPayloadIsIncomplete($subject, $content, $htmlContent, $suggestedName, $outputMode);
            if ($incomplete !== null) {
                $missing = $this->templateAiMissingFields($subject, $content, $htmlContent, $suggestedName, $outputMode);
                if ($missing !== []) {
                    $result = $this->retryTemplateAiForMissingFields(
                        $openAiService,
                        $messages,
                        $result['content'],
                        $missing,
                        $outputMode,
                        $mergeVars
                    );
                    $totalTokens += (int) ($result['total_tokens'] ?? 0);
                    $decoded = json_decode($result['content'], true);
                    if (is_array($decoded)) {
                        [$subject, $content, $htmlContent, $suggestedName] = $this->normalizeTemplateAiDecodedPayload($decoded, $outputMode);
                        if ($sendVia === 'sms') {
                            $content = $this->clampNewsletterSmsPlainBody($content);
                        }
                        $incomplete = $this->templateAiPayloadIsIncomplete($subject, $content, $htmlContent, $suggestedName, $outputMode);
                    }
                }
            }

            if ($incomplete !== null) {
                return $this->renderNewsletterTemplateForm($formTemplate, $incomplete);
            }

            $placeholderError = $this->validateTemplateAiMergeVariablesOnly($subject, $content, $htmlContent, $suggestedName);

            if ($placeholderError !== null) {
                $fixPrompt = <<<TXT
Your previous JSON used merge variables that this app does not support. Problem: {$placeholderError}

Return ONE new JSON object with the same keys and the same meaning (same email, same tone), but:
- ONLY these placeholders may appear inside single braces anywhere: {$mergeVars}
- Replace every other {word} with normal written text (full sentences or HTML text). For example use a short example story in plain words instead of {impact_story}; use href="#" and visible button text instead of {cta_link}.
- Keep suggested_name, subject, content, and html_content complete (all keys the same as before). No markdown fences.
TXT;

                $messages[] = ['role' => 'assistant', 'content' => $result['content']];
                $messages[] = ['role' => 'user', 'content' => $fixPrompt];

                $result = $this->newsletterAiChatCompletionJson($openAiService, $messages);
                $totalTokens += (int) ($result['total_tokens'] ?? 0);

                $decoded = json_decode($result['content'], true);
                if (! is_array($decoded)) {
                    return $this->renderNewsletterTemplateForm($formTemplate, [
                        'ok' => false,
                        'message' => 'AI could not fix merge variables. Please try generating again.',
                        'code' => 'invalid_response',
                    ]);
                }

                [$subject, $content, $htmlContent, $suggestedName] = $this->normalizeTemplateAiDecodedPayload($decoded, $outputMode);
                if ($sendVia === 'sms') {
                    $content = $this->clampNewsletterSmsPlainBody($content);
                }

                $incomplete = $this->templateAiPayloadIsIncomplete($subject, $content, $htmlContent, $suggestedName, $outputMode);
                if ($incomplete !== null) {
                    return $this->renderNewsletterTemplateForm($formTemplate, $incomplete);
                }

                $placeholderError = $this->validateTemplateAiMergeVariablesOnly($subject, $content, $htmlContent, $suggestedName);
                if ($placeholderError !== null) {
                    return $this->renderNewsletterTemplateForm($formTemplate, [
                        'ok' => false,
                        'message' => $placeholderError.' Please try generating again.',
                        'code' => 'invalid_placeholders',
                    ]);
                }
            }
            if ($totalTokens > 0) {
                $user->increment('ai_tokens_used', $totalTokens);
            }
            $user->refresh();

            Log::info('Newsletter template AI generated', [
                'user_id' => $user->id,
                'tokens_used' => $totalTokens,
                'ai_tokens_used' => $user->ai_tokens_used,
            ]);

            return $this->renderNewsletterTemplateForm($formTemplate, [
                'ok' => true,
                'output_mode' => $outputMode,
                'subject' => $subject,
                'content' => $content,
                'html_content' => $htmlContent,
                'suggested_name' => $suggestedName,
                'tokens_used' => $totalTokens,
                'ai_tokens_used' => (int) $user->ai_tokens_used,
                'ai_tokens_included' => (int) ($user->ai_tokens_included ?? 0),
            ]);
        } catch (\Exception $e) {
            Log::error('Newsletter template AI generation failed', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->renderNewsletterTemplateForm($formTemplate, [
                'ok' => false,
                'message' => $this->newsletterAiUserFacingMessage($e),
                'code' => 'api_error',
            ]);
        }
    }

    /**
     * Generate draft subject + body for the newsletter create page (one send, not a saved template).
     */
    public function generateNewsletterCreateWithAi(Request $request, OpenAiService $openAiService): Response
    {
        $validated = $request->validate([
            'brief' => 'required|string|max:3000',
            'template_type' => 'nullable|in:newsletter,announcement,event',
            'tone' => 'nullable|in:professional,warm,urgent,celebratory',
            'output_mode' => 'required|in:plain,html',
            'send_via' => 'nullable|in:email,sms,both,push',
        ]);

        $this->authorizePermission($request, 'newsletter.create');

        if (! $this->openAiApiKeyIsConfigured()) {
            return Inertia::render('newsletter/create', $this->newsletterCreatePageData([
                'ok' => false,
                'message' => 'AI is not configured. Set OPENAI_API_KEY on the server.',
                'code' => 'not_configured',
            ], $request));
        }

        $user = Auth::user();
        $tokensIncluded = (int) ($user->ai_tokens_included ?? 0);
        $tokensUsed = (int) ($user->ai_tokens_used ?? 0);
        if ($tokensIncluded > 0 && $tokensUsed >= $tokensIncluded) {
            return Inertia::render('newsletter/create', $this->newsletterCreatePageData([
                'ok' => false,
                'message' => 'You have used all your AI tokens for this period. Upgrade your plan or wait for your next allocation.',
                'code' => 'insufficient_tokens',
            ], $request));
        }

        $templateType = $validated['template_type'] ?? 'newsletter';
        $tone = $validated['tone'] ?? 'professional';
        $outputMode = $validated['output_mode'];
        $sendVia = $validated['send_via'] ?? 'email';
        if ($sendVia === 'sms' || $sendVia === 'push') {
            $outputMode = 'plain';
        } elseif ($sendVia === 'both') {
            $outputMode = 'html';
        }

        $typeLabels = [
            'newsletter' => 'a recurring nonprofit / community newsletter send',
            'announcement' => 'an important one-off announcement email send',
            'event' => 'an event invitation or reminder email send',
        ];
        $typeLabel = $typeLabels[$templateType] ?? $typeLabels['newsletter'];

        $mergeVars = $this->newsletterTemplateMergeVarsPromptLine();
        $plainQuality = $sendVia === 'sms'
            ? $this->newsletterAiSmsPlainCopyQualityRules()
            : $this->newsletterAiPlainCopyQualityRules();
        $htmlDesign = $this->newsletterAiHtmlDesignInstructions($tone);
        $toneCopyGuidance = $this->newsletterAiToneCopyGuidance($tone);

        if ($outputMode === 'plain') {
            $systemPrompt = <<<PROMPT
You help nonprofits write email campaigns. Respond with ONLY a single JSON object (no markdown fences).
Required keys: "subject" (string), "content" (string).

PLAIN TEXT OUTPUT ONLY for this request:
- Generate subject and the full email body as plain text only in "content".
- Do NOT use HTML tags anywhere. Set "html_content" to exactly "" (empty string).

This is for a single newsletter send (not a reusable template). Do not include a template name field.

{$plainQuality}

MERGE VARIABLES — STRICT (non-negotiable):
- The ONLY strings that may appear inside single curly braces {…} anywhere in subject or body are exactly: {$mergeVars}
- There are NO other merge fields. Do NOT invent placeholders for stories, blurbs, CTAs, URLs, or “sections”. Forbidden examples (never output these): {impact_story}, {story}, {body}, {main_content}, {cta_link}, {donate_link}, {event_info}, {donor_name}, {first_name}, {date}, or any {word} except those listed above.
- If the brief asks for an impact story, example copy, or event details: write that text in full as normal sentences—no curly braces around it.
- Do NOT use {{name}}, %email%, [FIRST_NAME], or double curly braces. No spaces inside braces (use {organization_name} not { organization_name }).

Subject: compelling, under 200 characters; you may include {organization_name} where it fits.
content: must include {unsubscribe_link}; you may include {public_view_link} where a browser view link is appropriate.

{$toneCopyGuidance}
PROMPT;
        } elseif ($outputMode === 'both') {
            $systemPrompt = <<<PROMPT
You help nonprofits write email campaigns. Respond with ONLY a single JSON object (no markdown fences).
Required keys: "subject" (string), "content" (string), "html_content" (string).

DUAL OUTPUT — full professional plain text + HTML for one send:

{$plainQuality}

- "content": Full text/plain body: multi-paragraph, \\n\\n between sections, same narrative as the HTML—not a short SMS-only blurb. Typical length when the brief allows: ~150–500+ words. No HTML tags. Merge variables only: {$mergeVars}. Include {unsubscribe_link} and {public_view_link} where appropriate.
- "html_content": Full email-safe HTML implementing the design specification below.

MERGE VARIABLES — STRICT in subject, content, and html_content:
- The ONLY strings inside single curly braces {…} are exactly: {$mergeVars}
- Do NOT use {{name}}, %email%, [NAME], or double curly braces.

{$htmlDesign}
PROMPT;
        } else {
            $systemPrompt = <<<PROMPT
You help nonprofits write email campaigns. Respond with ONLY a single JSON object (no markdown fences).
Required keys: "subject" (string), "html_content" (string), "content" (string).

HTML plus plain text (always include both):

{$plainQuality}

- "html_content": full email-safe HTML body implementing the design specification below.
- "content": REQUIRED full plain-text twin (no HTML tags, \\n line breaks). Merge variables only: {$mergeVars}. Include {unsubscribe_link} and optionally {public_view_link}. Faithfully match the HTML.

This is for a single newsletter send (not a reusable template). Do not include a template name field.

MERGE VARIABLES — STRICT (non-negotiable):
- The ONLY strings that may appear inside single curly braces {…} anywhere in subject, html_content, or content are exactly: {$mergeVars}
- There are NO other merge fields. Do NOT invent placeholders for stories, blurbs, CTAs, or URLs. Forbidden examples (never output these): {impact_story}, {story}, {body}, {main_content}, {cta_link}, {donate_link}, {event_info}, {donor_name}, {first_name}, or any {word} except those listed above.
- If the brief asks for an impact story, example copy, or event details: write that as real HTML text (paragraphs, lists)—never as a {placeholder}.
- Do NOT use {{name}}, %email%, [NAME], or double curly braces. No spaces inside braces.

Subject: compelling, under 200 characters; you may include {organization_name} where it fits.

{$htmlDesign}
PROMPT;
        }

        $modeLine = match ($outputMode) {
            'plain' => 'PLAIN TEXT ONLY (no HTML).',
            'both' => 'Full professional plain text in "content" PLUS full HTML in "html_content" (both required).',
            default => 'HTML in "html_content" AND a full plain-text twin in "content" (both required).',
        };
        $userPrompt = "Campaign purpose: {$typeLabel}.\nOutput mode: {$modeLine}\nDesired tone: {$tone}.\n\nBrief from the user:\n".$validated['brief'];
        if ($outputMode === 'html' || $outputMode === 'both') {
            $userPrompt .= "\n\nMandatory: HTML must use a distinctive palette with at least one dark or richly colored header, footer, or feature band—not an all-white-only layout. Follow the HTML VISUAL PERSONALITY for tone \"{$tone}\" (celebratory vs professional vs warm vs urgent should look obviously different).";
            $userPrompt .= "\n\nCRITICAL readability: light panels need dark text; dark header/hero bands need light text. Never dark slate body copy (#334155) on dark purple/navy backgrounds. Use a separate light-colored content block for the main story when the outer frame is dark.";
            $userPrompt .= "\n\nLayout: main story must be ONE readable column (full width inside 600px)—never CSS multi-column, never many side-by-side cells for the same article.";
            $userPrompt .= "\n\nDesign bar: same as template mode — dark header or hero strip, prominent headline, padded rounded cards, pill CTA with space before sign-off, no plain paragraph-wall + single accent button + accent-only footer without structure.";
        }
        if ($sendVia === 'sms') {
            $userPrompt .= "\n\nHard limit: \"content\" must be at most ".self::NEWSLETTER_SMS_PLAIN_MAX_CHARS.' characters (SMS segment).';
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $result = $this->newsletterAiChatCompletionJson($openAiService, $messages);
            $totalTokens = (int) ($result['total_tokens'] ?? 0);

            $decoded = json_decode($result['content'], true);
            if (! is_array($decoded)) {
                return Inertia::render('newsletter/create', $this->newsletterCreatePageData([
                    'ok' => false,
                    'message' => 'AI returned an invalid response. Please try again.',
                    'code' => 'invalid_response',
                ], $request));
            }

            [$subject, $content, $htmlContent] = $this->normalizeNewsletterCreateAiDecodedPayload($decoded, $outputMode);
            if ($sendVia === 'sms') {
                $content = $this->clampNewsletterSmsPlainBody($content);
            }

            $incomplete = $this->newsletterCreateAiPayloadIsIncomplete($subject, $content, $htmlContent, $outputMode);
            if ($incomplete !== null) {
                $missing = $this->templateAiMissingFields($subject, $content, $htmlContent, 'draft', $outputMode);
                if ($missing !== []) {
                    $result = $this->retryTemplateAiForMissingFields(
                        $openAiService,
                        $messages,
                        $result['content'],
                        $missing,
                        $outputMode,
                        $mergeVars
                    );
                    $totalTokens += (int) ($result['total_tokens'] ?? 0);
                    $decoded = json_decode($result['content'], true);
                    if (is_array($decoded)) {
                        [$subject, $content, $htmlContent] = $this->normalizeNewsletterCreateAiDecodedPayload($decoded, $outputMode);
                        if ($sendVia === 'sms') {
                            $content = $this->clampNewsletterSmsPlainBody($content);
                        }
                        $incomplete = $this->newsletterCreateAiPayloadIsIncomplete($subject, $content, $htmlContent, $outputMode);
                    }
                }
            }

            if ($incomplete !== null) {
                return Inertia::render('newsletter/create', $this->newsletterCreatePageData($incomplete, $request));
            }

            $placeholderError = $this->validateTemplateAiMergeVariablesOnly($subject, $content, $htmlContent, '');

            if ($placeholderError !== null) {
                $fixPrompt = <<<TXT
Your previous JSON used merge variables that this app does not support. Problem: {$placeholderError}

Return ONE new JSON object with the same keys and the same meaning (same email, same tone), but:
- ONLY these placeholders may appear inside single braces anywhere: {$mergeVars}
- Replace every other {word} with normal written text (full sentences or HTML text). For example use a short example story in plain words instead of {impact_story}; use href="#" and visible button text instead of {cta_link}.
- Keep subject, content, and html_content complete (same keys as before). No markdown fences.
TXT;

                $messages[] = ['role' => 'assistant', 'content' => $result['content']];
                $messages[] = ['role' => 'user', 'content' => $fixPrompt];

                $result = $this->newsletterAiChatCompletionJson($openAiService, $messages);
                $totalTokens += (int) ($result['total_tokens'] ?? 0);

                $decoded = json_decode($result['content'], true);
                if (! is_array($decoded)) {
                    return Inertia::render('newsletter/create', $this->newsletterCreatePageData([
                        'ok' => false,
                        'message' => 'AI could not fix merge variables. Please try generating again.',
                        'code' => 'invalid_response',
                    ], $request));
                }

                [$subject, $content, $htmlContent] = $this->normalizeNewsletterCreateAiDecodedPayload($decoded, $outputMode);
                if ($sendVia === 'sms') {
                    $content = $this->clampNewsletterSmsPlainBody($content);
                }

                $incomplete = $this->newsletterCreateAiPayloadIsIncomplete($subject, $content, $htmlContent, $outputMode);
                if ($incomplete !== null) {
                    return Inertia::render('newsletter/create', $this->newsletterCreatePageData($incomplete, $request));
                }

                $placeholderError = $this->validateTemplateAiMergeVariablesOnly($subject, $content, $htmlContent, '');
                if ($placeholderError !== null) {
                    return Inertia::render('newsletter/create', $this->newsletterCreatePageData([
                        'ok' => false,
                        'message' => $placeholderError.' Please try generating again.',
                        'code' => 'invalid_placeholders',
                    ], $request));
                }
            }
            if ($totalTokens > 0) {
                $user->increment('ai_tokens_used', $totalTokens);
            }
            $user->refresh();

            Log::info('Newsletter create AI generated', [
                'user_id' => $user->id,
                'tokens_used' => $totalTokens,
                'ai_tokens_used' => $user->ai_tokens_used,
            ]);

            return Inertia::render('newsletter/create', $this->newsletterCreatePageData([
                'ok' => true,
                'output_mode' => $outputMode,
                'subject' => $subject,
                'content' => $content,
                'html_content' => $htmlContent,
                'tokens_used' => $totalTokens,
                'ai_tokens_used' => (int) $user->ai_tokens_used,
                'ai_tokens_included' => (int) ($user->ai_tokens_included ?? 0),
            ], $request));
        } catch (\Exception $e) {
            Log::error('Newsletter create AI generation failed', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return Inertia::render('newsletter/create', $this->newsletterCreatePageData([
                'ok' => false,
                'message' => $this->newsletterAiUserFacingMessage($e),
                'code' => 'api_error',
            ], $request));
        }
    }

    /**
     * Store new template
     */
    public function storeTemplate(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'content' => 'nullable|string',
            'html_content' => 'nullable|string',
            'template_type' => 'required|in:newsletter,announcement,event',
            'settings' => 'nullable|array',
        ]);

        $contentTrim = trim((string) $request->content);
        $htmlTrim = trim((string) $request->html_content);
        if ($contentTrim === '' && $htmlTrim === '') {
            throw ValidationException::withMessages([
                'content' => 'Provide plain text content and/or HTML content.',
                'html_content' => 'Provide plain text content and/or HTML content.',
            ]);
        }

        $authOrg = Organization::forAuthUser($request->user());

        $template = NewsletterTemplate::create([
            'organization_id' => $authOrg?->id,
            'name' => $request->name,
            'subject' => $request->subject,
            'content' => $contentTrim === '' ? '' : $request->content,
            'html_content' => $htmlTrim === '' ? null : $request->html_content,
            'template_type' => $request->template_type,
            'settings' => $request->settings ?? [],
            'is_active' => true,
        ]);

        return redirect()->route('newsletter.templates')
            ->with('success', 'Template created successfully!');
    }

    /**
     * Show template details
     */
    public function showTemplate(Request $request, $id): Response
    {
        $this->authorizePermission($request, 'newsletter.read');

        $template = NewsletterTemplate::with('organization')->findOrFail($id);
        $this->assertNewsletterTemplateAccessible($request, $template);

        return Inertia::render('newsletter/template-show', [
            'template' => $template,
        ]);
    }

    /**
     * Edit template
     */
    public function editTemplate(Request $request, $id): Response
    {
        $this->authorizePermission($request, 'newsletter.edit');

        $template = NewsletterTemplate::findOrFail($id);
        $this->assertNewsletterTemplateAccessible($request, $template);

        return $this->renderNewsletterTemplateForm($template, null);
    }

    /**
     * Update template
     */
    public function updateTemplate(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.edit');

        $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'content' => 'nullable|string',
            'html_content' => 'nullable|string',
            'template_type' => 'required|in:newsletter,announcement,event',
            'settings' => 'nullable|array',
        ]);

        $contentTrim = trim((string) $request->content);
        $htmlTrim = trim((string) $request->html_content);
        if ($contentTrim === '' && $htmlTrim === '') {
            throw ValidationException::withMessages([
                'content' => 'Provide plain text content and/or HTML content.',
                'html_content' => 'Provide plain text content and/or HTML content.',
            ]);
        }

        $template = NewsletterTemplate::findOrFail($id);
        $this->assertNewsletterTemplateAccessible($request, $template);

        $template->update([
            'name' => $request->name,
            'subject' => $request->subject,
            'content' => $contentTrim === '' ? '' : $request->content,
            'html_content' => $htmlTrim === '' ? null : $request->html_content,
            'template_type' => $request->template_type,
            'settings' => $request->settings ?? $template->settings,
        ]);

        return redirect()->route('newsletter.templates')
            ->with('success', 'Template updated successfully!');
    }

    /**
     * Delete template
     */
    public function destroyTemplate(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.delete');

        $template = NewsletterTemplate::findOrFail($id);
        $this->assertNewsletterTemplateAccessible($request, $template);

        // Check if template is being used by any newsletters
        $newsletterCount = $template->newsletters()->count();
        if ($newsletterCount > 0) {
            return back()->with('error', "Cannot delete template. It's being used by {$newsletterCount} newsletter(s).");
        }

        $template->delete();

        return redirect()->route('newsletter.templates')
            ->with('success', 'Template deleted successfully!');
    }

    /**
     * Show recipients
     */
    public function recipients(Request $request): Response
    {
        $this->authorizePermission($request, 'newsletter.read');

        $orgScope = $this->newsletterOrganizationScope($request);

        $search = $request->input('search', '');
        $statusFilter = $request->input('status_filter', 'all');

        $organizationsQuery = $this->newsletterRecipientsOrganizationsQuery();
        $this->applyRecipientsOrganizationsScope($organizationsQuery, $orgScope);

        // Apply search filter
        if (! empty($search) && trim($search) !== '') {
            $organizationsQuery->where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Apply status filter
        if ($statusFilter && $statusFilter !== 'all') {
            if ($statusFilter === 'subscribed') {
                $organizationsQuery->whereHas('newsletterRecipients', function ($query) {
                    $query->where('status', 'active');
                });
            } elseif ($statusFilter === 'not_subscribed') {
                $organizationsQuery->whereDoesntHave('newsletterRecipients');
            } elseif ($statusFilter === 'unsubscribed') {
                $organizationsQuery->whereHas('newsletterRecipients', function ($query) {
                    $query->where('status', 'unsubscribed');
                });
            } elseif ($statusFilter === 'bounced') {
                $organizationsQuery->whereHas('newsletterRecipients', function ($query) {
                    $query->where('status', 'bounced');
                });
            }
        }

        $organizations = $organizationsQuery->latest()->paginate(20);

        $approvedOrgIds = Organization::query()
            ->active()
            ->excludingCareAllianceHubs()
            ->when($orgScope !== null, function (Builder $q) use ($orgScope) {
                if ($orgScope === []) {
                    $q->whereRaw('0 = 1');
                } else {
                    $q->whereIn('id', $orgScope);
                }
            })
            ->pluck('id');

        $totalOrganizations = $approvedOrgIds->count();
        $activeSubscriptions = NewsletterRecipient::active()->whereIn('organization_id', $approvedOrgIds)->count();
        $unsubscribed = NewsletterRecipient::unsubscribed()->whereIn('organization_id', $approvedOrgIds)->count();
        $bounced = NewsletterRecipient::bounced()->whereIn('organization_id', $approvedOrgIds)->count();

        $notSubscribed = Organization::query()
            ->active()
            ->excludingCareAllianceHubs()
            ->when($orgScope !== null, function (Builder $q) use ($orgScope) {
                if ($orgScope === []) {
                    $q->whereRaw('0 = 1');
                } else {
                    $q->whereIn('id', $orgScope);
                }
            })
            ->whereDoesntHave('newsletterRecipients')
            ->count();

        $stats = [
            'total_organizations' => $totalOrganizations,
            'active_subscriptions' => $activeSubscriptions,
            'unsubscribed' => $unsubscribed,
            'bounced' => $bounced,
            'not_subscribed' => $notSubscribed,
        ];

        // Manual contacts: admins see legacy rows with no org; others only see contacts tied to their org(s).
        $manualRecipientsQuery = NewsletterRecipient::query();
        if ($orgScope === null) {
            $manualRecipientsQuery->whereNull('organization_id');
        } elseif ($orgScope === []) {
            $manualRecipientsQuery->whereRaw('0 = 1');
        } else {
            $manualRecipientsQuery->whereIn('organization_id', $orgScope);
        }

        // Apply search filter for manual recipients
        $manualSearch = $request->input('manual_search', '');
        if (! empty($manualSearch) && trim($manualSearch) !== '') {
            $manualRecipientsQuery->where(function ($query) use ($manualSearch) {
                $query->where('email', 'LIKE', "%{$manualSearch}%")
                    ->orWhere('name', 'LIKE', "%{$manualSearch}%");
            });
        }

        $manualRecipients = $manualRecipientsQuery->latest()->paginate(10);

        return Inertia::render('newsletter/recipients', [
            'organizations' => $organizations,
            'manualRecipients' => $manualRecipients,
            'stats' => $stats,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'manualSearch' => $manualSearch,
        ]);
    }

    /**
     * Store new recipient (Recipients page or newsletter create inline form).
     * When {@code return_to=newsletter.create}, redirect back to create with {@code audience_segment=newsletter_contacts}.
     */
    public function storeRecipient(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $backToCreate = $request->input('return_to') === 'newsletter.create';
        $inertiaSilentCreate = $backToCreate && $request->inertia();

        $redirectAfter = function () use ($backToCreate) {
            if ($backToCreate) {
                return redirect()->route('newsletter.create', ['audience_segment' => 'newsletter_contacts']);
            }

            return back();
        };

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'return_to' => 'nullable|in:newsletter.create',
        ]);

        if ($validator->fails()) {
            if ($inertiaSilentCreate) {
                return $this->inertiaNewsletterCreateAudience($request, [
                    'errors' => $validator->errors(),
                ]);
            }

            return $redirectAfter()
                ->withErrors($validator)
                ->withInput();
        }

        $email = (string) $request->input('email');

        $existingRecipient = NewsletterRecipient::where('email', $email)->first();

        if ($existingRecipient) {
            if ($inertiaSilentCreate) {
                return $this->inertiaNewsletterCreateAudience($request, [
                    'newsletterRecipientInlineNotice' => [
                        'type' => 'error',
                        'message' => 'This email is already subscribed to the newsletter.',
                    ],
                ]);
            }

            return $redirectAfter()
                ->with('error', 'This email is already subscribed to the newsletter.')
                ->withInput();
        }

        $recipientOrg = $request->user() instanceof User ? Organization::forAuthUser($request->user()) : null;

        NewsletterRecipient::create([
            'organization_id' => $recipientOrg?->id,
            'email' => $email,
            'name' => $request->input('name'),
            'status' => 'active',
            'subscribed_at' => now(),
        ]);

        if ($inertiaSilentCreate) {
            return $this->inertiaNewsletterCreateAudience($request, [
                'newsletterRecipientInlineNotice' => [
                    'type' => 'success',
                    'message' => 'Recipient added successfully!',
                ],
            ]);
        }

        return $redirectAfter()->with('success', 'Recipient added successfully!');
    }

    /**
     * Manual recipient belongs to an org in the user's scope, or is a legacy null-org row (same rules as before for non-admins).
     */
    protected function assertCanManageManualRecipient(Request $request, NewsletterRecipient $recipient): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $scope = $this->newsletterOrganizationScope($request);
        if ($scope === null) {
            return;
        }
        if ($scope === []) {
            abort(403);
        }
        if ($recipient->organization_id === null) {
            if (! Organization::forAuthUser($user)) {
                abort(403);
            }

            return;
        }
        if (! in_array((int) $recipient->organization_id, $scope, true)) {
            abort(403);
        }
    }

    /**
     * Update a manual recipient (email / name). Supports silent Inertia return to newsletter create.
     */
    public function updateManualRecipient(Request $request, NewsletterRecipient $recipient)
    {
        $this->authorizePermission($request, 'newsletter.create');
        $this->assertCanManageManualRecipient($request, $recipient);

        $backToCreate = $request->input('return_to') === 'newsletter.create';
        $inertiaSilentCreate = $backToCreate && $request->inertia();

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'return_to' => 'nullable|in:newsletter.create',
        ]);

        if ($validator->fails()) {
            if ($inertiaSilentCreate) {
                return $this->inertiaNewsletterCreateAudience($request, [
                    'errors' => $validator->errors(),
                ]);
            }

            return back()->withErrors($validator)->withInput();
        }

        $email = (string) $request->input('email');

        $duplicate = NewsletterRecipient::where('email', $email)->where('id', '!=', $recipient->id)->first();
        if ($duplicate) {
            if ($inertiaSilentCreate) {
                return $this->inertiaNewsletterCreateAudience($request, [
                    'newsletterRecipientInlineNotice' => [
                        'type' => 'error',
                        'message' => 'Another recipient already uses this email.',
                    ],
                ]);
            }

            return back()->with('error', 'Another recipient already uses this email.')->withInput();
        }

        $recipient->update([
            'email' => $email,
            'name' => $request->input('name'),
        ]);

        if ($inertiaSilentCreate) {
            return $this->inertiaNewsletterCreateAudience($request, [
                'newsletterRecipientInlineNotice' => [
                    'type' => 'success',
                    'message' => 'Recipient updated.',
                ],
            ]);
        }

        return back()->with('success', 'Recipient updated successfully!');
    }

    /**
     * Delete a manual recipient. Supports silent Inertia return to newsletter create.
     */
    public function destroyManualRecipient(Request $request, NewsletterRecipient $recipient)
    {
        $this->authorizePermission($request, 'newsletter.edit');
        $this->assertCanManageManualRecipient($request, $recipient);

        $backToCreate = $request->input('return_to') === 'newsletter.create';
        $inertiaSilentCreate = $backToCreate && $request->inertia();

        $recipient->delete();

        if ($inertiaSilentCreate) {
            return $this->inertiaNewsletterCreateAudience($request, [
                'newsletterRecipientInlineNotice' => [
                    'type' => 'success',
                    'message' => 'Recipient removed.',
                ],
            ]);
        }

        return back()->with('success', 'Recipient deleted successfully!');
    }

    /**
     * Subscribe organization to newsletter
     */
    public function subscribeOrganization(Request $request, $organizationId)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $organization = Organization::findOrFail($organizationId);
        $this->assertOrganizationInUserScope($request, $organization);

        // Check if already has an active subscription
        $existingRecipient = NewsletterRecipient::where('email', $organization->email)->first();

        if ($existingRecipient && $existingRecipient->status === 'active') {
            return back()->with('error', 'This organization is already subscribed to the newsletter.');
        }

        if ($existingRecipient) {
            // Update existing recipient to active
            $existingRecipient->update([
                'status' => 'active',
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ]);
        } else {
            // Create new recipient
            NewsletterRecipient::create([
                'organization_id' => $organization->id,
                'email' => $organization->email,
                'name' => $organization->user->name ?? $organization->name,
                'status' => 'active',
                'subscribed_at' => now(),
            ]);
        }

        return back()->with('success', 'Organization subscribed successfully!');
    }

    /**
     * Unsubscribe organization from newsletter
     */
    public function unsubscribeOrganization(Request $request, $organizationId)
    {
        $this->authorizePermission($request, 'newsletter.edit');

        $organization = Organization::findOrFail($organizationId);
        $this->assertOrganizationInUserScope($request, $organization);

        // Find and update the recipient status
        $recipient = NewsletterRecipient::where('organization_id', $organization->id)
            ->orWhere('email', $organization->email)
            ->first();

        if ($recipient) {
            $recipient->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);

            return back()->with('success', 'Organization unsubscribed successfully!');
        }

        return back()->with('error', 'Organization is not subscribed to the newsletter.');
    }

    /**
     * Send test email
     */
    public function sendTestEmail(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.send');

        $request->validate([
            'email' => 'required|email',
            'subject' => 'required|string',
            'content' => 'required|string',
        ]);

        try {
            // Send test email
            Mail::raw($request->content, function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('[TEST] '.$request->subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            Log::info('Test email sent', [
                'to' => $request->email,
                'subject' => $request->subject,
                'sent_by' => Auth::id(),
            ]);

            return back()->with('success', 'Test email sent successfully to '.$request->email.'!');
        } catch (\Exception $e) {
            Log::error('Test email failed', [
                'to' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to send test email: '.$e->getMessage());
        }
    }

    /**
     * Export recipients
     */
    public function exportRecipients(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.read');

        $orgScope = $this->newsletterOrganizationScope($request);

        $search = $request->input('search', '');
        $statusFilter = $request->input('status_filter', 'all');

        $organizationsQuery = $this->newsletterRecipientsOrganizationsQuery();
        $this->applyRecipientsOrganizationsScope($organizationsQuery, $orgScope);

        // Apply search filter
        if (! empty($search) && trim($search) !== '') {
            $organizationsQuery->where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Apply status filter
        if ($statusFilter && $statusFilter !== 'all') {
            if ($statusFilter === 'subscribed') {
                $organizationsQuery->whereHas('newsletterRecipients', function ($query) {
                    $query->where('status', 'active');
                });
            } elseif ($statusFilter === 'not_subscribed') {
                $organizationsQuery->whereDoesntHave('newsletterRecipients');
            } elseif ($statusFilter === 'unsubscribed') {
                $organizationsQuery->whereHas('newsletterRecipients', function ($query) {
                    $query->where('status', 'unsubscribed');
                });
            } elseif ($statusFilter === 'bounced') {
                $organizationsQuery->whereHas('newsletterRecipients', function ($query) {
                    $query->where('status', 'bounced');
                });
            }
        }

        $organizations = $organizationsQuery->get();

        $filename = 'newsletter_recipients_'.date('Y-m-d_H-i-s').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($organizations) {
            $file = fopen('php://output', 'w');

            // CSV headers
            fputcsv($file, [
                'Organization Name',
                'Organization Email',
                'Contact Person',
                'Contact Email',
                'Registration Status',
                'Newsletter Status',
                'Subscribed Date',
                'Created Date',
            ]);

            foreach ($organizations as $org) {
                $subscription = $org->newsletterRecipients?->first();
                $subscriptionStatus = $subscription?->status ?? 'not_subscribed';

                fputcsv($file, [
                    $org->name,
                    $org->email,
                    $org->user?->name ?? 'N/A',
                    $org->user?->email ?? 'N/A',
                    $org->registration_status,
                    $subscriptionStatus,
                    $subscription?->subscribed_at ? date('Y-m-d H:i:s', strtotime($subscription->subscribed_at)) : 'N/A',
                    date('Y-m-d H:i:s', strtotime($org->created_at)),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Import recipients from CSV
     */
    public function importRecipients(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();

        $imported = 0;
        $errors = [];

        $importOrg = Auth::user() instanceof User ? Organization::forAuthUser(Auth::user()) : null;
        $importOrgId = $importOrg?->id;

        if (($handle = fopen($path, 'r')) !== false) {
            // Skip header row
            fgetcsv($handle);

            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                if (count($data) >= 2) {
                    $email = trim($data[0]);
                    $name = isset($data[1]) ? trim($data[1]) : null;

                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        // Check if recipient already exists
                        $existing = NewsletterRecipient::where('email', $email)->first();

                        if (! $existing) {
                            NewsletterRecipient::create([
                                'organization_id' => $importOrgId,
                                'email' => $email,
                                'name' => $name,
                                'status' => 'active',
                                'subscribed_at' => now(),
                            ]);
                            $imported++;
                        } else {
                            $errors[] = "Email {$email} already exists";
                        }
                    } else {
                        $errors[] = "Invalid email: {$email}";
                    }
                }
            }
            fclose($handle);
        }

        $message = "Successfully imported {$imported} recipients.";
        if (! empty($errors)) {
            $message .= ' Errors: '.implode(', ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $message .= ' and '.(count($errors) - 5).' more errors.';
            }
        }

        return back()->with('success', $message);
    }

    /**
     * Subscribe manual recipient
     */
    public function subscribeManualRecipient(Request $request, $recipientId)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $recipient = NewsletterRecipient::findOrFail($recipientId);
        $this->assertCanManageManualRecipient($request, $recipient);

        $backToCreate = $request->input('return_to') === 'newsletter.create';
        $inertiaSilent = $backToCreate && $request->inertia();

        if ($recipient->status === 'active') {
            if ($inertiaSilent) {
                return $this->inertiaNewsletterCreateAudience($request, [
                    'newsletterRecipientInlineNotice' => [
                        'type' => 'error',
                        'message' => 'This recipient is already subscribed.',
                    ],
                ]);
            }

            return back()->with('error', 'This recipient is already subscribed.');
        }

        $recipient->update([
            'status' => 'active',
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
        ]);

        if ($inertiaSilent) {
            return $this->inertiaNewsletterCreateAudience($request, [
                'newsletterRecipientInlineNotice' => [
                    'type' => 'success',
                    'message' => 'Recipient subscribed successfully!',
                ],
            ]);
        }

        return back()->with('success', 'Recipient subscribed successfully!');
    }

    /**
     * Unsubscribe manual recipient
     */
    public function unsubscribeManualRecipient(Request $request, $recipientId)
    {
        $this->authorizePermission($request, 'newsletter.edit');

        $recipient = NewsletterRecipient::findOrFail($recipientId);
        $this->assertCanManageManualRecipient($request, $recipient);

        $backToCreate = $request->input('return_to') === 'newsletter.create';
        $inertiaSilent = $backToCreate && $request->inertia();

        if ($recipient->status !== 'active') {
            if ($inertiaSilent) {
                return $this->inertiaNewsletterCreateAudience($request, [
                    'newsletterRecipientInlineNotice' => [
                        'type' => 'error',
                        'message' => 'This recipient is not currently subscribed.',
                    ],
                ]);
            }

            return back()->with('error', 'This recipient is not currently subscribed.');
        }

        $recipient->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        if ($inertiaSilent) {
            return $this->inertiaNewsletterCreateAudience($request, [
                'newsletterRecipientInlineNotice' => [
                    'type' => 'success',
                    'message' => 'Recipient unsubscribed successfully!',
                ],
            ]);
        }

        return back()->with('success', 'Recipient unsubscribed successfully!');
    }

    /**
     * Create new newsletter
     */
    public function create(Request $request): Response
    {
        $this->authorizePermission($request, 'newsletter.create');

        return Inertia::render('newsletter/create', $this->newsletterCreatePageData(null, $request));
    }

    /**
     * Store new newsletter
     */
    public function store(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        // Debug the request data
        Log::info('Newsletter store request data:', [
            'send_date' => $request->send_date,
            'schedule_type' => $request->schedule_type,
            'user_timezone' => TimezoneService::requestTimezone($request),
            'browser_timezone' => $request->header('X-Timezone'),
            'all_data' => $request->all(),
        ]);

        if ($request->filled('newsletter_template_id')) {
            $request->merge(['newsletter_template_id' => (int) $request->newsletter_template_id]);
        } else {
            $request->merge(['newsletter_template_id' => null]);
        }

        // Custom validation for send_date based on schedule_type
        $rules = [
            'newsletter_template_id' => 'nullable|exists:newsletter_templates,id',
            'subject' => 'required|string|max:255',
            'content' => $request->input('send_via') === 'sms'
                ? 'required|string|max:'.self::NEWSLETTER_SMS_PLAIN_MAX_CHARS
                : ($request->input('send_via') === 'push'
                    ? 'required|string|max:20000'
                    : 'nullable|string'),
            'html_content' => 'nullable|string',
            'send_via' => 'required|in:email,sms,both,push',
            'scheduled_at' => 'nullable|date|after:now',
            'schedule_type' => 'required|in:immediate,scheduled,recurring',
            'recurring_settings' => 'nullable|array',
            'target_type' => 'required|in:all,users,organizations,specific,roles',
            'target_users' => 'nullable|array',
            'target_organizations' => 'nullable|array',
            'target_roles' => 'nullable|array',
            'target_criteria' => 'nullable|array',
            'target_criteria.organization_segment' => 'nullable|in:followers,donors,volunteers,newsletter_contacts',
            'is_public' => 'boolean',
        ];

        // Add send_date validation based on schedule_type
        // Note: We allow past dates since late newsletters will be sent immediately
        if ($request->schedule_type === 'scheduled' || $request->schedule_type === 'recurring') {
            $rules['send_date'] = 'required|date';
        } else {
            $rules['send_date'] = 'nullable|date';
        }

        // Custom validation to check date in user's timezone context
        // We removed 'after_or_equal:now' to allow past dates (late newsletters will be sent immediately)
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);

        $userTimezone = TimezoneService::requestTimezone($request);
        $validator->after(function ($validator) use ($request, $userTimezone) {
            if ($request->input('target_type') === 'roles') {
                $roles = $request->input('target_roles', []);
                if (! is_array($roles) || $roles === [] || ! collect($roles)->filter(fn ($r) => is_string($r) && $r !== '')->isNotEmpty()) {
                    $validator->errors()->add('target_roles', 'Select at least one role to send to those users.');
                }
            }

            $targetType = $request->input('target_type');
            if (in_array($targetType, ['organizations', 'specific', 'roles'], true)) {
                $u = $request->user();
                if ($u instanceof User && ! $this->userCanUseNewsletterProTargeting($u)) {
                    $validator->errors()->add(
                        'target_type',
                        'By role, Organizations, and Custom targeting require the Pro newsletter targeting purchase (or admin access).'
                    );
                }
            }

            if (($request->schedule_type === 'scheduled' || $request->schedule_type === 'recurring') && $request->send_date) {
                try {
                    $sendDate = Carbon::parse($request->send_date, $userTimezone);
                    $now = Carbon::now();

                    // Allow past dates (late newsletters will be sent immediately)
                    // But warn if it's more than 24 hours in the past
                    if ($sendDate->lt($now->copy()->subDay())) {
                        // More than 24 hours late - might be a mistake, but allow it
                        Log::warning('Newsletter scheduled for more than 24 hours in the past', [
                            'send_date' => $sendDate->toDateTimeString(),
                            'current_time' => $now->toDateTimeString(),
                            'timezone' => $userTimezone,
                        ]);
                    }
                } catch (\Exception $e) {
                    $validator->errors()->add('send_date', 'Invalid date format.');
                }
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        if ($request->filled('newsletter_template_id')) {
            $tpl = NewsletterTemplate::findOrFail((int) $request->newsletter_template_id);
            $this->assertNewsletterTemplateAccessible($request, $tpl);
        }

        $sendVia = $request->input('send_via', 'email');
        $contentTrim = trim((string) $request->content);
        $htmlTrim = trim((string) $request->html_content);

        if ($sendVia === 'sms') {
            if ($contentTrim === '') {
                throw ValidationException::withMessages([
                    'content' => 'Plain text content is required for SMS.',
                ]);
            }
            $htmlTrim = '';
        } elseif ($sendVia === 'both') {
            if ($contentTrim === '') {
                throw ValidationException::withMessages([
                    'content' => 'Plain text content is required for the SMS leg of this send.',
                ]);
            }
            if ($htmlTrim === '') {
                throw ValidationException::withMessages([
                    'html_content' => 'HTML content is required when sending both SMS and email (email uses the HTML version).',
                ]);
            }
        } elseif ($sendVia === 'push') {
            if (trim((string) $request->subject) === '') {
                throw ValidationException::withMessages([
                    'subject' => 'A subject is required for push (used as the notification title).',
                ]);
            }
            if ($contentTrim === '') {
                throw ValidationException::withMessages([
                    'content' => 'Plain text body is required for push notifications.',
                ]);
            }
        } elseif ($contentTrim === '' && $htmlTrim === '') {
            throw ValidationException::withMessages([
                'content' => 'Provide plain text content and/or HTML content.',
                'html_content' => 'Provide plain text content and/or HTML content.',
            ]);
        }

        // Calculate send date based on schedule type
        $sendDate = null;
        $status = 'draft';

        $userTimezone = TimezoneService::requestTimezone($request);

        switch ($request->schedule_type) {
            case 'immediate':
                $status = 'draft';
                $sendDate = null;
                break;
            case 'scheduled':
                // Parse date explicitly in user's timezone, then convert to UTC for storage
                if ($request->send_date) {
                    // Create Carbon instance in user's timezone, then convert to UTC
                    $sendDate = Carbon::createFromFormat('Y-m-d\TH:i', $request->send_date, $userTimezone)->utc();
                } else {
                    $sendDate = null;
                }
                $status = 'scheduled';
                break;
            case 'recurring':
                // Parse date explicitly in user's timezone, then convert to UTC for storage
                if ($request->send_date) {
                    $sendDate = Carbon::createFromFormat('Y-m-d\TH:i', $request->send_date, $userTimezone)->utc();
                } else {
                    $sendDate = null;
                }
                $status = 'scheduled';
                break;
        }

        // Get authenticated user and their organization
        $user = Auth::user();
        $userOrganization = Organization::forAuthUser($user);

        $newsletter = DB::transaction(function () use ($request, $userOrganization, $contentTrim, $htmlTrim, $sendVia, $status, $sendDate) {
            $newsletter = new Newsletter([
                'organization_id' => $userOrganization->id ?? null,
                'newsletter_template_id' => $request->input('newsletter_template_id'),
                'subject' => $request->subject,
                'content' => $contentTrim === '' ? '' : $request->content,
                'html_content' => $htmlTrim === '' ? null : $request->html_content,
                'send_via' => $sendVia,
                'status' => $status,
                'scheduled_at' => $sendDate,
                'send_date' => $sendDate,
                'schedule_type' => $request->schedule_type,
                'recurring_settings' => $request->recurring_settings,
                'target_type' => $request->target_type,
                'target_users' => $request->target_users,
                'target_organizations' => $request->target_organizations,
                'target_roles' => $request->target_roles,
                'target_criteria' => $request->target_criteria,
                'is_public' => $request->is_public ?? false,
            ]);

            $newsletter->total_recipients = $newsletter->resolvedTotalRecipientsCount();
            $newsletter->save();

            $templateName = trim((string) $request->subject) !== ''
                ? mb_substr(trim((string) $request->subject).' · '.now()->format('M j, Y g:i A'), 0, 255)
                : mb_substr('Newsletter · '.now()->format('M j, Y g:i A'), 0, 255);

            $template = NewsletterTemplate::create([
                'organization_id' => $newsletter->organization_id,
                'name' => $templateName,
                'subject' => $request->subject,
                'content' => $contentTrim === '' ? '' : $request->content,
                'html_content' => $htmlTrim === '' ? null : $request->html_content,
                'template_type' => 'newsletter',
                'settings' => [],
                'is_active' => true,
            ]);

            $newsletter->update(['newsletter_template_id' => $template->id]);

            return $newsletter->fresh();
        });

        return redirect()->route('newsletter.show', $newsletter->id)
            ->with('success', 'Newsletter created successfully! A matching template was saved for reuse.');
    }

    /**
     * Show newsletter details
     */
    public function show(Request $request, $id): Response
    {
        $this->authorizePermission($request, 'newsletter.read');

        $newsletter = Newsletter::with(['template', 'organization', 'emails.recipient'])
            ->select([
                'id', 'subject', 'content', 'html_content', 'send_via', 'status',
                'scheduled_at', 'send_date', 'sent_at', 'schedule_type',
                'total_recipients', 'sent_count', 'delivered_count',
                'opened_count', 'clicked_count', 'bounced_count',
                'unsubscribed_count', 'newsletter_template_id', 'organization_id',
                'created_at', 'updated_at',
            ])
            ->findOrFail($id);

        $this->assertNewsletterAccessible($request, $newsletter);

        // Format all dates - Convert from UTC (database) to user's timezone
        $userTimezone = TimezoneService::requestTimezone($request);

        if ($newsletter->scheduled_at) {
            // Get raw UTC value from database - Laravel stores as UTC string
            $rawValue = $newsletter->getRawOriginal('scheduled_at') ?? $newsletter->scheduled_at;
            $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
            $newsletter->scheduled_at_formatted = $date->format('M d, Y h:i A');
            $newsletter->scheduled_at_iso = $date->toISOString();
        }
        if ($newsletter->send_date) {
            // Get raw UTC value from database
            $rawValue = $newsletter->getRawOriginal('send_date') ?? $newsletter->send_date;
            $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
            $newsletter->send_date_formatted = $date->format('M d, Y h:i A');
            $newsletter->send_date_iso = $date->toISOString();
        }
        if ($newsletter->sent_at) {
            $rawValue = $newsletter->getRawOriginal('sent_at') ?? $newsletter->sent_at;
            $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
            $newsletter->sent_at_formatted = $date->format('M d, Y h:i A');
        }
        if ($newsletter->created_at) {
            $rawValue = $newsletter->getRawOriginal('created_at') ?? $newsletter->created_at;
            $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
            $newsletter->created_at_formatted = $date->format('M d, Y h:i A');
        }

        // Format email dates
        $newsletter->emails->transform(function ($email) use ($userTimezone) {
            if ($email->sent_at) {
                $rawValue = $email->getRawOriginal('sent_at') ?? $email->sent_at;
                $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
                $email->sent_at_formatted = $date->format('M d, Y h:i A');
            }
            if ($email->delivered_at) {
                $rawValue = $email->getRawOriginal('delivered_at') ?? $email->delivered_at;
                $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
                $email->delivered_at_formatted = $date->format('M d, Y h:i A');
            }
            if ($email->opened_at) {
                $rawValue = $email->getRawOriginal('opened_at') ?? $email->opened_at;
                $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
                $email->opened_at_formatted = $date->format('M d, Y h:i A');
            }
            if ($email->clicked_at) {
                $rawValue = $email->getRawOriginal('clicked_at') ?? $email->clicked_at;
                $date = Carbon::parse($rawValue, 'UTC')->setTimezone($userTimezone);
                $email->clicked_at_formatted = $date->format('M d, Y h:i A');
            }

            return $email;
        });

        // Ensure emails have proper structure even if recipient is deleted
        $newsletter->emails->each(function ($email) {
            if (! $email->recipient) {
                // If recipient is deleted, we still have the email address
                $email->recipient = null;
            }
        });

        $user = Auth::user();

        // Build organization address from available fields
        $orgAddress = '';
        if ($user->organization) {
            $addressParts = array_filter([
                $user->organization->street,
                $user->organization->city,
                $user->organization->state,
                $user->organization->zip,
            ]);
            $orgAddress = ! empty($addressParts) ? implode(', ', $addressParts) : 'Your Organization Address';
        }

        // Carbon automatically uses config('app.timezone') set by middleware
        $currentDate = Carbon::now()->format('F j, Y');
        $currentYear = (string) Carbon::now()->year;

        // Get real data for variable preview
        // For public view link, use the actual newsletter public URL
        $publicViewLink = route('newsletter.show', $newsletter->id);

        $previewData = [
            'organization_name' => $user->organization->name ?? ($user->name ?? 'Your Organization'),
            'organization_email' => $user->organization->email ?? ($user->email ?? 'support@believeinunity.org'),
            'organization_phone' => $user->organization->phone ?? ($user->contact_number ?? '+1 (555) 000-0000'),
            'organization_address' => $orgAddress ?: 'Your Organization Address',
            'recipient_name' => $user->name ?? 'Recipient Name',
            'recipient_email' => $user->email ?? 'recipient@example.com',
            'current_date' => $currentDate,
            'current_year' => $currentYear,
            'unsubscribe_link' => url('/newsletter/unsubscribe?token=preview_token'),
            'public_view_link' => $publicViewLink,
        ];

        return Inertia::render('newsletter/show', [
            'newsletter' => $newsletter,
            'previewData' => $previewData,
        ]);
    }

    /**
     * Send newsletter
     */
    public function send(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.send');

        $newsletter = Newsletter::with('organization.user')->findOrFail($id);

        $this->assertNewsletterAccessible($request, $newsletter);

        if (! in_array($newsletter->status, ['draft', 'paused'])) {
            return back()->with('error', 'Newsletter can only be sent from draft or paused status.');
        }

        $sendVia = $newsletter->send_via ?? 'email';
        $walletUser = Auth::user();
        if (! $walletUser) {
            return back()->with('error', 'You must be signed in to send.');
        }
        $smsLeft = max(0, (int) ($walletUser->sms_included ?? 0) - (int) ($walletUser->sms_used ?? 0));
        $emailsLeft = max(0, (int) ($walletUser->emails_included ?? 0) - (int) ($walletUser->emails_used ?? 0));
        if ($sendVia === 'sms' && $smsLeft < 1) {
            return back()->with('error', 'No SMS credits remaining. Open Create Newsletter or Templates and purchase an SMS pack ($25 = 1,200 SMS) before sending SMS.');
        }
        if ($sendVia === 'email' && $emailsLeft < 1) {
            return back()->with('error', 'No email credits remaining. Purchase an email pack from the Newsletter or Templates page before sending.');
        }
        if ($sendVia === 'both' && $smsLeft < 1 && $emailsLeft < 1) {
            return back()->with('error', 'No email or SMS credits remaining. Purchase packs from the Newsletter or Templates page before sending.');
        }

        $meta = $newsletter->metadata ?? [];
        $meta = is_array($meta) ? $meta : [];
        $meta['billing_user_id'] = (int) $walletUser->id;

        // Update status to sending (billing_user_id must match Auth user so wallet UI matches SendNewsletterJob deductions)
        $newsletter->update([
            'status' => 'sending',
            'metadata' => $meta,
        ]);

        // Use new targeting system to get recipients
        try {
            $targetedUsers = $newsletter->getTargetedUsers();

            if ($targetedUsers->isEmpty()) {
                $newsletter->update(['status' => 'failed']);

                return back()->with('error', 'No recipients found to send the newsletter to. Please check your targeting settings.');
            }

            // Dispatch job to send emails (job will create email records)
            try {
                dispatch(new SendNewsletterJob($newsletter));
            } catch (\Throwable $e) {
                $newsletter->update(['status' => 'failed']);
                throw $e;
            }

            return back()->with('success', 'Newsletter is being sent to '.$targetedUsers->count().' recipients.');
        } catch (\Exception $e) {
            Log::error('Error in send method', [
                'newsletter_id' => $newsletter->id,
                'error' => $e->getMessage(),
            ]);
            $newsletter->update(['status' => 'failed']);

            return back()->with('error', 'Failed to send newsletter: '.$e->getMessage());
        }
    }

    /**
     * Get average open rate
     */
    private function getAverageOpenRate(?array $organizationScope): float
    {
        $query = Newsletter::where('status', 'sent');
        $this->applyNewsletterOrganizationScope($query, $organizationScope);

        $total = $query->sum('delivered_count');
        $opened = $query->sum('opened_count');

        return $total > 0 ? round(($opened / $total) * 100, 2) : 0;
    }

    /**
     * Get average click rate
     */
    private function getAverageClickRate(?array $organizationScope): float
    {
        $query = Newsletter::where('status', 'sent');
        $this->applyNewsletterOrganizationScope($query, $organizationScope);

        $total = $query->sum('delivered_count');
        $clicked = $query->sum('clicked_count');

        return $total > 0 ? round(($clicked / $total) * 100, 2) : 0;
    }

    /**
     * Edit newsletter
     */
    public function edit(Request $request, $id): Response
    {
        $this->authorizePermission($request, 'newsletter.edit');

        $newsletter = Newsletter::with(['template'])->findOrFail($id);
        $this->assertNewsletterAccessible($request, $newsletter);

        $orgScope = $this->newsletterOrganizationScope($request);
        $templatesQuery = NewsletterTemplate::where('is_active', true);
        $this->applyNewsletterOrganizationScope($templatesQuery, $orgScope);
        $templates = $templatesQuery->get();

        $user = Auth::user();

        // Build organization address from available fields
        $orgAddress = '';
        if ($user->organization) {
            $addressParts = array_filter([
                $user->organization->street,
                $user->organization->city,
                $user->organization->state,
                $user->organization->zip,
            ]);
            $orgAddress = ! empty($addressParts) ? implode(', ', $addressParts) : 'Your Organization Address';
        }

        // Get real data for variable preview
        // For public view link, use the actual newsletter public URL if available
        $publicViewLink = route('newsletter.show', $newsletter->id);

        $previewData = [
            'organization_name' => $user->organization->name ?? ($user->name ?? 'Your Organization'),
            'organization_email' => $user->organization->email ?? ($user->email ?? 'support@believeinunity.org'),
            'organization_phone' => $user->organization->phone ?? ($user->contact_number ?? '+1 (555) 000-0000'),
            'organization_address' => $orgAddress ?: 'Your Organization Address',
            'recipient_name' => $user->name ?? 'Recipient Name',
            'recipient_email' => $user->email ?? 'recipient@example.com',
            'current_date' => Carbon::now()->format('F j, Y'),
            'current_year' => (string) Carbon::now()->year,
            'unsubscribe_link' => url('/newsletter/unsubscribe?token=preview_token'),
            'public_view_link' => $publicViewLink,
        ];

        return Inertia::render('newsletter/edit', [
            'newsletter' => $newsletter,
            'templates' => $templates,
            'previewData' => $previewData,
        ]);
    }

    /**
     * Update newsletter
     */
    public function update(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.update');

        $newsletter = Newsletter::findOrFail($id);
        $this->assertNewsletterAccessible($request, $newsletter);

        if ($request->filled('newsletter_template_id')) {
            $request->merge(['newsletter_template_id' => (int) $request->newsletter_template_id]);
        } else {
            $request->merge(['newsletter_template_id' => null]);
        }

        $request->validate([
            'subject' => 'required|string|max:255',
            'content' => $request->input('send_via') === 'sms'
                ? 'required|string|max:'.self::NEWSLETTER_SMS_PLAIN_MAX_CHARS
                : ($request->input('send_via') === 'push'
                    ? 'required|string|max:20000'
                    : 'nullable|string'),
            'html_content' => 'nullable|string',
            'newsletter_template_id' => 'nullable|exists:newsletter_templates,id',
            'send_via' => 'required|in:email,sms,both,push',
        ]);

        if ($request->filled('newsletter_template_id')) {
            $tpl = NewsletterTemplate::findOrFail((int) $request->newsletter_template_id);
            $this->assertNewsletterTemplateAccessible($request, $tpl);
        }

        $sendVia = $request->input('send_via', 'email');
        $contentTrim = trim((string) $request->content);
        $htmlTrim = trim((string) $request->html_content);

        if ($sendVia === 'sms') {
            if ($contentTrim === '') {
                throw ValidationException::withMessages([
                    'content' => 'Plain text content is required for SMS.',
                ]);
            }
            $htmlTrim = '';
        } elseif ($sendVia === 'both') {
            if ($contentTrim === '') {
                throw ValidationException::withMessages([
                    'content' => 'Plain text content is required for the SMS leg of this send.',
                ]);
            }
            if ($htmlTrim === '') {
                throw ValidationException::withMessages([
                    'html_content' => 'HTML content is required when sending both SMS and email.',
                ]);
            }
        } elseif ($sendVia === 'push') {
            if (trim((string) $request->subject) === '') {
                throw ValidationException::withMessages([
                    'subject' => 'A subject is required for push (used as the notification title).',
                ]);
            }
            if ($contentTrim === '') {
                throw ValidationException::withMessages([
                    'content' => 'Plain text body is required for push notifications.',
                ]);
            }
        } elseif ($contentTrim === '' && $htmlTrim === '') {
            throw ValidationException::withMessages([
                'content' => 'Provide plain text content and/or HTML content.',
                'html_content' => 'Provide plain text content and/or HTML content.',
            ]);
        }

        $newsletter->update([
            'subject' => $request->subject,
            'content' => $contentTrim === '' ? '' : $request->content,
            'html_content' => $htmlTrim === '' ? null : $request->html_content,
            'newsletter_template_id' => $request->input('newsletter_template_id'),
            'send_via' => $sendVia,
            'status' => 'draft', // Reset to draft when editing
        ]);

        return redirect()->route('newsletter.show', $newsletter->id)
            ->with('success', 'Newsletter updated successfully!');
    }

    /**
     * Update newsletter schedule
     */
    public function updateSchedule(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.update');

        $newsletter = Newsletter::findOrFail($id);
        $this->assertNewsletterAccessible($request, $newsletter);

        // Only allow schedule update for scheduled newsletters (not sent, failed, or sending)
        if (! in_array($newsletter->status, ['scheduled', 'draft'])) {
            return back()->with('error', 'Only scheduled or draft newsletters can have their schedule updated.');
        }

        // Allow updating to past dates (for late newsletters) or future dates
        $request->validate([
            'scheduled_at' => 'required|date',
        ]);

        $userTimezone = TimezoneService::requestTimezone($request);

        \Illuminate\Support\Facades\Log::info('Update schedule debug:', [
            'scheduled_at_input' => $request->scheduled_at,
            'user_timezone' => $userTimezone,
            'browser_timezone' => $request->header('X-Timezone'),
        ]);

        $scheduledAt = Carbon::parse($request->scheduled_at, $userTimezone)->utc();

        $localTime = $scheduledAt->copy()->setTimezone($userTimezone);

        Log::info('Schedule conversion result:', [
            'original_input' => $request->scheduled_at,
            'user_timezone' => $userTimezone,
            'converted_utc' => $scheduledAt->toISOString(),
            'converted_local' => $localTime->toDateTimeString(),
            'converted_local_iso' => $localTime->toISOString(),
            'verification' => "User entered {$request->scheduled_at} in {$userTimezone}, stored as {$scheduledAt->toDateTimeString()} UTC, which is {$localTime->toDateTimeString()} in {$userTimezone}",
        ]);

        // Check if updating to a past date (late newsletter)
        $isLate = $scheduledAt->lt(now());
        if ($isLate) {
            Log::warning('Updating newsletter schedule to past date - will be sent immediately', [
                'newsletter_id' => $newsletter->id,
                'scheduled_at' => $scheduledAt->toISOString(),
                'current_time' => now()->toISOString(),
            ]);
        }

        // Update both scheduled_at and send_date to keep them in sync
        $newsletter->update([
            'scheduled_at' => $scheduledAt,
            'send_date' => $scheduledAt, // Keep send_date in sync
        ]);

        $message = $isLate
            ? 'Newsletter schedule updated. Since the time is in the past, it will be sent immediately when the scheduler runs.'
            : 'Newsletter schedule updated successfully.';

        $localTime = $scheduledAt->copy()->setTimezone($userTimezone);

        Log::info('Newsletter schedule updated', [
            'newsletter_id' => $newsletter->id,
            'old_scheduled_at_utc' => $newsletter->getOriginal('scheduled_at'),
            'old_scheduled_at_local' => $newsletter->getOriginal('scheduled_at') ? Carbon::parse($newsletter->getOriginal('scheduled_at'), 'UTC')->setTimezone($userTimezone)->toDateTimeString() : null,
            'new_scheduled_at_utc' => $scheduledAt->toISOString(),
            'new_scheduled_at_local' => $localTime->toDateTimeString(),
            'old_send_date_utc' => $newsletter->getOriginal('send_date'),
            'new_send_date_utc' => $scheduledAt->toISOString(),
            'is_late' => $isLate,
            'user_timezone' => TimezoneService::requestTimezone($request),
        ]);

        return redirect()->route('newsletter.index')
            ->with('success', $message);
    }

    /**
     * Pause newsletter (move back to draft)
     */
    public function pause(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.update');

        $newsletter = Newsletter::findOrFail($id);
        $this->assertNewsletterAccessible($request, $newsletter);

        // Allow pausing for scheduled and sending newsletters
        if (! in_array($newsletter->status, ['scheduled', 'sending'])) {
            return back()->with('error', 'Only scheduled or sending newsletters can be paused.');
        }

        $newsletter->update([
            'status' => 'paused',
            'scheduled_at' => null,
        ]);

        return redirect()->route('newsletter.index')
            ->with('success', 'Newsletter paused successfully!');
    }

    /**
     * Resume newsletter (schedule for sending)
     */
    public function resume(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.update');

        $newsletter = Newsletter::findOrFail($id);
        $this->assertNewsletterAccessible($request, $newsletter);

        // Only allow resuming for paused newsletters
        if ($newsletter->status !== 'paused') {
            return back()->with('error', 'Only paused newsletters can be resumed.');
        }

        $request->validate([
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $newsletter->update([
            'status' => $request->scheduled_at ? 'scheduled' : 'draft',
            'scheduled_at' => $request->scheduled_at,
        ]);

        $message = $request->scheduled_at ?
            'Newsletter scheduled successfully!' :
            'Newsletter resumed and ready to send!';

        return redirect()->route('newsletter.index')
            ->with('success', $message);
    }

    /**
     * Manually send newsletter (admin override)
     */
    public function manualSend(Request $request, $id)
    {
        Log::info('Manual send request received', [
            'newsletter_id' => $id,
            'user_id' => Auth::id(),
            'request_data' => $request->all(),
        ]);

        $this->authorizePermission($request, 'newsletter.send');

        $newsletter = Newsletter::with('organization.user')->findOrFail($id);

        $this->assertNewsletterAccessible($request, $newsletter);

        Log::info('Newsletter found for manual send', [
            'newsletter_id' => $newsletter->id,
            'current_status' => $newsletter->status,
            'subject' => $newsletter->subject,
            'target_type' => $newsletter->target_type ?? 'all',
            'target_users' => $newsletter->target_users ?? [],
            'target_organizations' => $newsletter->target_organizations ?? [],
        ]);

        try {
            // Allow manual sending for any status (including sent for "Send Again" functionality)
            if ($newsletter->status === 'sending') {
                $stuckForMinutes = $newsletter->updated_at
                    ? $newsletter->updated_at->diffInMinutes(Carbon::now('UTC'))
                    : 0;

                if ($stuckForMinutes < 5) {
                    return back()->with('error', 'Engagement is currently being sent. Please wait a few minutes for it to complete.');
                }

                Log::warning('Recovering stuck newsletter send', [
                    'newsletter_id' => $newsletter->id,
                    'stuck_for_minutes' => $stuckForMinutes,
                    'send_via' => $newsletter->send_via,
                ]);

                dispatch(new SendNewsletterJob($newsletter));

                return redirect()->route('newsletter.index')
                    ->with('success', 'Sending looked stuck — the delivery job was queued again. If the status does not change, start a queue worker: php artisan queue:work --queue=default,mail');
            }

            $sendVia = $newsletter->send_via ?? 'email';
            $walletUser = Auth::user();
            if (! $walletUser) {
                return back()->with('error', 'You must be signed in to send.');
            }
            $smsLeft = max(0, (int) ($walletUser->sms_included ?? 0) - (int) ($walletUser->sms_used ?? 0));
            $emailsLeft = max(0, (int) ($walletUser->emails_included ?? 0) - (int) ($walletUser->emails_used ?? 0));
            if ($sendVia === 'sms' && $smsLeft < 1) {
                return back()->with('error', 'No SMS credits remaining. Purchase an SMS pack from the Newsletter create or template page before sending SMS.');
            }
            if ($sendVia === 'email' && $emailsLeft < 1) {
                return back()->with('error', 'No email credits remaining. Purchase an email pack from the Newsletter or Templates page before sending.');
            }
            if ($sendVia === 'both' && $smsLeft < 1 && $emailsLeft < 1) {
                return back()->with('error', 'No email or SMS credits remaining. Purchase packs from the Newsletter or Templates page before sending.');
            }

            // Store original status for "Send Again" logic
            $wasSent = $newsletter->status === 'sent';

            $meta = $newsletter->metadata ?? [];
            $meta = is_array($meta) ? $meta : [];
            $meta['billing_user_id'] = (int) $walletUser->id;

            // Update status to sending
            $newsletter->update([
                'status' => 'sending',
                'scheduled_at' => now(),
                'metadata' => $meta,
            ]);

            Log::info('Newsletter status updated to sending', [
                'newsletter_id' => $newsletter->id,
            ]);

            // Clear existing email records if this is a "Send Again" (newsletter was previously sent)
            if ($wasSent) {
                NewsletterEmail::where('newsletter_id', $newsletter->id)->delete();
                Log::info('Cleared existing email records for send again', [
                    'newsletter_id' => $newsletter->id,
                ]);
            }

            // Use new targeting system - getTargetedUsers() handles all cases including 'all'
            // The job will create email records from the targeted users
            Log::info('Determining recipients using targeting system', [
                'newsletter_id' => $newsletter->id,
                'target_type' => $newsletter->target_type ?? 'all',
            ]);

            try {
                $targetedUsers = $newsletter->getTargetedUsers();
                Log::info('Got targeted users', [
                    'newsletter_id' => $newsletter->id,
                    'targeted_users_count' => $targetedUsers->count(),
                ]);

                if ($targetedUsers->isEmpty()) {
                    $newsletter->update(['status' => 'failed']);
                    Log::warning('No targeted recipients found', [
                        'newsletter_id' => $newsletter->id,
                        'target_type' => $newsletter->target_type,
                    ]);

                    return back()->with('error', 'No recipients found to send the newsletter to. Please check your targeting settings or add recipients.');
                }

                // Create email records for targeted users (let the job handle it, but we can pre-create for immediate feedback)
                // Actually, let the job handle this to avoid duplicate creation
                Log::info('Recipients determined, job will create email records', [
                    'newsletter_id' => $newsletter->id,
                    'recipients_count' => $targetedUsers->count(),
                ]);
            } catch (\Exception $e) {
                Log::error('Error getting targeted users', [
                    'newsletter_id' => $newsletter->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $newsletter->update(['status' => 'failed']);

                return back()->with('error', 'Error determining recipients: '.$e->getMessage());
            }

            // Dispatch job to send emails
            Log::info('Dispatching SendNewsletterJob', [
                'newsletter_id' => $newsletter->id,
            ]);

            try {
                dispatch(new SendNewsletterJob($newsletter));
            } catch (\Throwable $e) {
                $newsletter->update(['status' => $wasSent ? 'sent' : 'failed']);
                throw $e;
            }

            // Get recipient count for message
            $recipientCount = 0;
            try {
                $recipientCount = $newsletter->getTargetedUsers()->count();
            } catch (\Exception $e) {
                Log::error('Error counting targeted users', [
                    'newsletter_id' => $newsletter->id,
                    'error' => $e->getMessage(),
                ]);
                // Use a fallback count from email records if they exist
                $recipientCount = NewsletterEmail::where('newsletter_id', $newsletter->id)
                    ->where('status', 'pending')
                    ->count();
            }

            $message = $wasSent ?
                'Newsletter is being sent again to '.$recipientCount.' recipients.' :
                'Newsletter is being sent to '.$recipientCount.' recipients.';

            Log::info('Newsletter manual send completed', [
                'newsletter_id' => $newsletter->id,
                'recipients_count' => $recipientCount,
                'was_sent' => $wasSent,
                'message' => $message,
            ]);

            return redirect()->route('newsletter.index')
                ->with('success', $message);

        } catch (\Exception $e) {
            Log::error('Error in manual send', [
                'newsletter_id' => $newsletter->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $newsletter->update(['status' => 'failed']);

            return back()->with('error', 'Failed to send newsletter: '.$e->getMessage());
        }
    }

    /**
     * Delete newsletter
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizePermission($request, 'newsletter.delete');

        $newsletter = Newsletter::findOrFail($id);

        $this->assertNewsletterAccessible($request, $newsletter);

        Log::info('Newsletter delete request', [
            'newsletter_id' => $newsletter->id,
            'status' => $newsletter->status,
            'user_id' => Auth::id(),
        ]);

        // Allow deletion of any status except currently sending (to prevent data loss)
        if ($newsletter->status === 'sending') {
            return back()->with('error', 'Cannot delete newsletter while it is being sent. Please wait for it to complete or pause it first.');
        }

        try {
            // Delete associated email records first
            NewsletterEmail::where('newsletter_id', $newsletter->id)->delete();

            // Delete the newsletter
            $newsletter->delete();

            Log::info('Newsletter deleted successfully', [
                'newsletter_id' => $id,
            ]);

            return redirect()->route('newsletter.index')
                ->with('success', 'Newsletter deleted successfully!');
        } catch (\Exception $e) {
            Log::error('Error deleting newsletter', [
                'newsletter_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to delete newsletter: '.$e->getMessage());
        }
    }

    /**
     * Stripe checkout to add prepaid SMS credits (same flow as email-invite purchase).
     */
    public function purchaseSms(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $request->validate([
            'package_id' => 'required|exists:sms_packages,id',
            'return_to' => 'nullable|in:newsletter,pay-as-you-go',
        ]);

        $user = $request->user();
        $package = SmsPackage::active()->findOrFail((int) $request->input('package_id'));
        $returnTo = $request->input('return_to');
        $returnToNewsletter = $returnTo === 'newsletter';
        $returnToPayg = $returnTo === 'pay-as-you-go';

        try {
            $transaction = $user->recordTransaction([
                'type' => 'sms_purchase',
                'amount' => $package->price,
                'payment_method' => 'stripe',
                'status' => 'pending',
                'meta' => [
                    'type' => 'sms_purchase',
                    'sms_to_add' => $package->sms_count,
                    'package_id' => $package->id,
                    'package_name' => $package->name,
                    'description' => 'Purchase '.$package->name,
                ],
            ]);

            $amountInCents = StripeCustomerChargeAmount::chargeCentsFromNetUsd((float) $package->price, 'card');

            $successQs = 'session_id={CHECKOUT_SESSION_ID}';
            if ($returnToNewsletter) {
                $successQs .= '&return_to=newsletter&open_buy=sms';
            } elseif ($returnToPayg) {
                $successQs .= '&return_to=pay-as-you-go&open=sms';
            }

            $cancelUrl = $returnToNewsletter
                ? route('newsletter.index', ['canceled' => '1', 'open_buy' => 'sms'])
                : ($returnToPayg
                    ? route('pay-as-you-go.index', ['canceled' => '1', 'open' => 'sms'])
                    : route('newsletter.create').'?canceled=1');

            $checkout = $user->checkoutCharge(
                $amountInCents,
                $package->name,
                1,
                [
                    'success_url' => route('newsletter.purchase-sms.success').'?'.$successQs,
                    'cancel_url' => $cancelUrl,
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'transaction_id' => (string) $transaction->id,
                        'type' => 'sms_purchase',
                        'sms_to_add' => (string) $package->sms_count,
                        'package_id' => (string) $package->id,
                        'amount' => (string) $package->price,
                        'return_to' => $returnToNewsletter ? 'newsletter' : ($returnToPayg ? 'pay-as-you-go' : ''),
                    ],
                    'payment_method_types' => ['card'],
                ]
            );

            return Inertia::location($checkout->url);
        } catch (\Exception $e) {
            Log::error('SMS pack checkout error', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return back()->withErrors([
                'message' => 'Failed to create checkout session. Please try again.',
            ]);
        }
    }

    /**
     * Stripe return URL after successful SMS pack payment.
     */
    public function purchaseSmsSuccess(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        try {
            $sessionId = $request->query('session_id');
            $returnTo = $request->query('return_to');
            $toNewsletter = $returnTo === 'newsletter';
            $toPayg = $returnTo === 'pay-as-you-go';

            if (! $sessionId) {
                if ($toPayg) {
                    return redirect()->route('pay-as-you-go.index', ['error' => 'Invalid session.', 'open' => 'sms']);
                }

                return $toNewsletter
                    ? redirect()->route('newsletter.index', [
                        'error' => 'Invalid session.',
                        'open_buy' => 'sms',
                    ])
                    : redirect()->route('newsletter.create')->with('error', 'Invalid session.');
            }

            $user = $request->user();
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

            $metadata = is_object($session->metadata) ? (array) $session->metadata : (array) ($session->metadata ?? []);
            if (! $toNewsletter && (($metadata['return_to'] ?? '') === 'newsletter')) {
                $toNewsletter = true;
            }
            if (! $toPayg && (($metadata['return_to'] ?? '') === 'pay-as-you-go')) {
                $toPayg = true;
            }

            if ($session->payment_status !== 'paid') {
                if ($toPayg) {
                    return redirect()->route('pay-as-you-go.index', [
                        'error' => 'Payment was not completed.',
                        'open' => 'sms',
                    ]);
                }

                return $toNewsletter
                    ? redirect()->route('newsletter.index', [
                        'error' => 'Payment was not completed.',
                        'open_buy' => 'sms',
                    ])
                    : redirect()->route('newsletter.create')->with('error', 'Payment was not completed.');
            }
            $smsToAdd = (int) ($metadata['sms_to_add'] ?? 0);
            $transactionId = $metadata['transaction_id'] ?? null;

            if ($smsToAdd > 0) {
                $user->increment('sms_included', $smsToAdd);
            }

            if ($transactionId) {
                $transaction = \App\Models\Transaction::find($transactionId);
                if ($transaction) {
                    $transaction->update([
                        'status' => 'completed',
                        'meta' => array_merge($transaction->meta ?? [], [
                            'type' => 'sms_purchase',
                            'stripe_session_id' => $sessionId,
                            'stripe_payment_intent' => $session->payment_intent,
                            'sms_added' => $smsToAdd,
                        ]),
                    ]);
                }
            }

            $successMsg = "Successfully added {$smsToAdd} SMS credits to your wallet!";

            if ($toPayg) {
                return redirect()->route('pay-as-you-go.index', ['success' => $successMsg]);
            }

            return $toNewsletter
                ? redirect()->route('newsletter.index', [
                    'success' => $successMsg,
                    'open_buy' => 'sms',
                ])
                : redirect()->route('newsletter.create')->with('success', $successMsg);
        } catch (\Exception $e) {
            Log::error('SMS purchase success handler error', [
                'error' => $e->getMessage(),
                'session_id' => $request->query('session_id'),
            ]);

            $returnTo = $request->query('return_to');
            $toNewsletter = $returnTo === 'newsletter';
            $toPayg = $returnTo === 'pay-as-you-go';

            if ($toPayg) {
                return redirect()->route('pay-as-you-go.index', [
                    'error' => 'Error confirming payment. Please contact support.',
                ]);
            }

            return $toNewsletter
                ? redirect()->route('newsletter.index', [
                    'error' => 'Error confirming payment. Please contact support.',
                    'open_buy' => 'sms',
                ])
                : redirect()->route('newsletter.create')->with('error', 'Error confirming payment. Please contact support.');
        }
    }

    /**
     * SMS wallet: auto-recharge toggle, threshold, and package (charges via Laravel Cashier off-session).
     */
    public function updateSmsWalletPreferences(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $user = $request->user();

        $validated = $request->validate([
            'sms_auto_recharge_enabled' => 'required|boolean',
            'sms_auto_recharge_threshold' => 'nullable|integer|min:0|max:10000000',
            'sms_auto_recharge_package_id' => 'nullable|integer|exists:sms_packages,id',
        ]);

        $enabled = (bool) $validated['sms_auto_recharge_enabled'];
        $threshold = array_key_exists('sms_auto_recharge_threshold', $validated) && $validated['sms_auto_recharge_threshold'] !== null
            ? (int) $validated['sms_auto_recharge_threshold']
            : null;
        $packageId = array_key_exists('sms_auto_recharge_package_id', $validated) && $validated['sms_auto_recharge_package_id'] !== null
            ? (int) $validated['sms_auto_recharge_package_id']
            : null;

        if ($packageId !== null) {
            $pkg = SmsPackage::active()->whereKey($packageId)->first();
            if (! $pkg) {
                throw ValidationException::withMessages([
                    'sms_auto_recharge_package_id' => 'Select an active SMS package.',
                ]);
            }
        }

        if ($enabled) {
            $request->validate([
                'sms_auto_recharge_threshold' => 'required|integer|min:0|max:10000000',
                'sms_auto_recharge_package_id' => 'required|integer|exists:sms_packages,id',
                'sms_auto_recharge_policy_accepted' => 'required|accepted',
            ]);
            if (! $user->sms_auto_recharge_pm_id) {
                throw ValidationException::withMessages([
                    'sms_auto_recharge_enabled' => 'Add a saved card for SMS auto-recharge before enabling.',
                ]);
            }
        }

        $payload = [
            'sms_auto_recharge_enabled' => $enabled,
            'sms_auto_recharge_threshold' => $threshold,
            'sms_auto_recharge_package_id' => $packageId,
        ];
        if ($enabled) {
            $payload['sms_auto_recharge_agreed_at'] = now();
        }
        $user->update($payload);

        if ($enabled) {
            $user->refresh();
            Bus::dispatchSync(new ProcessSmsWalletAutoRechargeJob($user->id));
        }

        return back()->with('success', 'SMS wallet preferences updated.');
    }

    /**
     * Stripe Checkout (setup mode) to save a card for SMS auto-recharge (Cashier customer).
     */
    public function smsAutoRechargeSetupPayment(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $user = $request->user();

        try {
            $user->createOrGetStripeCustomer();
            $user->refresh();

            if (! $user->stripe_id) {
                return redirect()->route('newsletter.index', [
                    'error' => 'Could not create a Stripe billing profile. Please try again or contact support.',
                    'open_buy' => 'sms',
                ]);
            }

            $currency = strtolower((string) config('cashier.currency', 'usd'));

            $session = Cashier::stripe()->checkout->sessions->create([
                'customer' => $user->stripe_id,
                'mode' => 'setup',
                'currency' => $currency,
                'payment_method_types' => ['card'],
                'success_url' => route('newsletter.sms-auto-recharge.setup-success').'?session_id={CHECKOUT_SESSION_ID}&open_buy=sms',
                'cancel_url' => route('newsletter.index', ['canceled' => '1', 'open_buy' => 'sms']),
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'type' => 'sms_auto_recharge_setup',
                ],
            ]);

            return Inertia::location($session->url);
        } catch (ApiErrorException $e) {
            Log::error('SMS auto-recharge setup Stripe error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('newsletter.index', [
                'error' => 'Could not start card setup. Please try again or contact support.',
                'open_buy' => 'sms',
            ]);
        } catch (\Throwable $e) {
            Log::error('SMS auto-recharge setup session error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('newsletter.index', [
                'error' => 'Could not start card setup. Please try again.',
                'open_buy' => 'sms',
            ]);
        }
    }

    /**
     * Return from Stripe setup mode — store payment method for off-session SMS purchases.
     */
    public function smsAutoRechargeSetupSuccess(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $user = $request->user();
        $sessionId = $request->query('session_id');

        if (! $sessionId) {
            return redirect()->route('newsletter.index', [
                'error' => 'Invalid setup session.',
                'open_buy' => 'sms',
            ]);
        }

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve((string) $sessionId);
            if (($session->metadata->type ?? '') !== 'sms_auto_recharge_setup'
                || (int) ($session->metadata->user_id ?? 0) !== (int) $user->id) {
                return redirect()->route('newsletter.index', [
                    'error' => 'This setup session does not match your account.',
                    'open_buy' => 'sms',
                ]);
            }

            $setupIntentId = $session->setup_intent;
            if (! $setupIntentId) {
                return redirect()->route('newsletter.index', [
                    'error' => 'Could not confirm your card. Please try again.',
                    'open_buy' => 'sms',
                ]);
            }

            $setupIntent = Cashier::stripe()->setupIntents->retrieve(
                is_string($setupIntentId) ? $setupIntentId : $setupIntentId->id
            );
            $pmId = is_string($setupIntent->payment_method)
                ? $setupIntent->payment_method
                : ($setupIntent->payment_method->id ?? null);
            if (! $pmId) {
                return redirect()->route('newsletter.index', [
                    'error' => 'No payment method was saved.',
                    'open_buy' => 'sms',
                ]);
            }

            BelievePointsPaymentMethodSyncService::ensurePaymentMethodBelongsToCustomer($user, $pmId);
            $pm = Cashier::stripe()->paymentMethods->retrieve($pmId);
            $card = $pm->card ?? null;

            $user->update([
                'sms_auto_recharge_pm_id' => $pmId,
                'sms_auto_recharge_card_brand' => $card->brand ?? null,
                'sms_auto_recharge_card_last4' => $card->last4 ?? null,
            ]);

            $user->refresh();
            if ($user->sms_auto_recharge_enabled) {
                Bus::dispatchSync(new ProcessSmsWalletAutoRechargeJob($user->id));
            }

            $successMsg = 'Card saved. Set threshold and package, then enable SMS auto-recharge.';

            return redirect()->route('newsletter.index', [
                'success' => $successMsg,
                'open_buy' => 'sms',
            ]);
        } catch (\Exception $e) {
            Log::error('SMS auto-recharge setup success error: '.$e->getMessage());

            return redirect()->route('newsletter.index', [
                'error' => 'Could not save your card. Please try again.',
                'open_buy' => 'sms',
            ]);
        }
    }

    /**
     * Remove saved card and turn off SMS auto-recharge.
     */
    public function smsAutoRechargeRemovePaymentMethod(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        $request->user()->update([
            'sms_auto_recharge_enabled' => false,
            'sms_auto_recharge_pm_id' => null,
            'sms_auto_recharge_card_brand' => null,
            'sms_auto_recharge_card_last4' => null,
        ]);

        return back()->with('success', 'Saved card removed and SMS auto-recharge turned off.');
    }

    /**
     * Unsubscribe from newsletter
     */
    public function unsubscribe(Request $request, $token)
    {
        $recipient = NewsletterRecipient::where('unsubscribe_token', $token)->first();

        if (! $recipient) {
            return view('newsletter.unsubscribe', [
                'success' => false,
                'message' => 'Invalid unsubscribe link.',
            ]);
        }

        if ($recipient->status === 'unsubscribed') {
            return view('newsletter.unsubscribe', [
                'success' => true,
                'message' => 'You have already unsubscribed from this newsletter.',
            ]);
        }

        $recipient->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        return view('newsletter.unsubscribe', [
            'success' => true,
            'message' => 'You have been successfully unsubscribed from the newsletter.',
            'recipient' => $recipient,
        ]);
    }

    /**
     * By role, Organizations, and Custom newsletter targeting (Pro): not tied to generic subscription/plan —
     * only explicit one-time purchase (newsletter_pro_targeting_purchased_at) or platform admin.
     */
    private function userCanUseNewsletterProTargeting(User $user): bool
    {
        if (($user->role ?? '') === 'admin') {
            return true;
        }
        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }
        if ($user->newsletter_pro_targeting_purchased_at !== null) {
            return true;
        }

        return false;
    }

    /**
     * One-time Stripe checkout — lifetime access to Organizations + Custom targeting (no subscription plan row).
     */
    public function purchaseProTargeting(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        if (! config('newsletter.pro_targeting_purchase_enabled', true)) {
            return back()->with('error', 'Pro targeting purchase is not available right now.');
        }

        $priceUsd = (float) config('newsletter.pro_targeting_lifetime_price_usd', 0);
        if ($priceUsd <= 0) {
            return back()->with('error', 'Pro targeting price is not configured.');
        }

        $user = $request->user();
        if ($user instanceof User && $this->userCanUseNewsletterProTargeting($user)) {
            return redirect()->route('newsletter.create-advanced')->with('success', 'You already have access to Pro newsletter targeting.');
        }

        try {
            $transaction = $user->recordTransaction([
                'type' => 'newsletter_pro_targeting_lifetime',
                'amount' => $priceUsd,
                'payment_method' => 'stripe',
                'status' => 'pending',
                'meta' => [
                    'type' => 'newsletter_pro_targeting_lifetime',
                    'description' => 'Newsletter Pro targeting (Organizations + Custom) — lifetime',
                    'price_usd' => $priceUsd,
                ],
            ]);

            $amountInCents = StripeCustomerChargeAmount::chargeCentsFromNetUsd((float) $priceUsd, 'card');

            $checkout = $user->checkoutCharge(
                $amountInCents,
                'Newsletter Pro targeting (lifetime)',
                1,
                [
                    'success_url' => route('newsletter.purchase-pro-targeting.success').'?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('newsletter.create-advanced').'?canceled=1',
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'transaction_id' => (string) $transaction->id,
                        'type' => 'newsletter_pro_targeting_lifetime',
                        'amount' => (string) $priceUsd,
                    ],
                    'payment_method_types' => ['card'],
                ]
            );

            return Inertia::location($checkout->url);
        } catch (\Exception $e) {
            Log::error('Newsletter Pro targeting checkout error', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return back()->withErrors([
                'message' => 'Failed to start checkout. Please try again.',
            ]);
        }
    }

    /**
     * Stripe return after successful Pro targeting one-time payment.
     */
    public function purchaseProTargetingSuccess(Request $request)
    {
        $this->authorizePermission($request, 'newsletter.create');

        try {
            $sessionId = $request->query('session_id');
            if (! $sessionId) {
                return redirect()->route('newsletter.create-advanced')->with('error', 'Invalid session.');
            }

            $user = $request->user();
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

            if ($session->payment_status !== 'paid') {
                return redirect()->route('newsletter.create-advanced')->with('error', 'Payment was not completed.');
            }

            $metadata = $session->metadata ?? [];
            if (($metadata['type'] ?? '') !== 'newsletter_pro_targeting_lifetime') {
                return redirect()->route('newsletter.create-advanced')->with('error', 'Invalid payment type.');
            }

            $user->forceFill([
                'newsletter_pro_targeting_purchased_at' => now(),
            ])->save();

            $transactionId = $metadata['transaction_id'] ?? null;
            if ($transactionId) {
                $transaction = \App\Models\Transaction::find($transactionId);
                if ($transaction) {
                    $paymentIntentRef = $session->payment_intent ?? null;
                    $paymentIntentId = is_string($paymentIntentRef)
                        ? $paymentIntentRef
                        : (is_object($paymentIntentRef) ? ($paymentIntentRef->id ?? null) : null);

                    $transaction->update([
                        'status' => 'completed',
                        'meta' => array_merge($transaction->meta ?? [], [
                            'type' => 'newsletter_pro_targeting_lifetime',
                            'stripe_session_id' => $sessionId,
                            'stripe_payment_intent' => $paymentIntentId ?? $session->payment_intent,
                        ]),
                    ]);
                }
            }

            return redirect()->route('newsletter.create-advanced')->with(
                'success',
                'Pro newsletter targeting is unlocked for your account — lifetime access to By role, Organizations, and Custom.'
            );
        } catch (\Exception $e) {
            Log::error('Newsletter Pro targeting success handler error', [
                'error' => $e->getMessage(),
                'session_id' => $request->query('session_id'),
            ]);

            return redirect()->route('newsletter.create-advanced')->with('error', 'Could not confirm payment. Contact support if you were charged.');
        }
    }
}
