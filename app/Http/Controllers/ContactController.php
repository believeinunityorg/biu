<?php

namespace App\Http\Controllers;

use App\Models\ContactPageContent;
use App\Models\ContactSubmission;
use App\Services\SeoService;
use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function index()
    {
        // Fetch all dynamic content
        $hero = ContactPageContent::bySection('hero')->active()->first();
        $contactMethods = ContactPageContent::bySection('contact_methods')->active()->ordered()->get();
        $faqItems = ContactPageContent::bySection('faq')->active()->ordered()->get();
        $officeHours = ContactPageContent::bySection('office_hours')->active()->first();
        $cta = ContactPageContent::bySection('cta')->active()->first();

        return Inertia::render('frontend/contact', [
            'seo' => SeoService::forPage('contact'),
            'hero' => $hero ? $hero->content : null,
            'contactMethods' => $contactMethods->map(fn ($item) => $item->content),
            'faqItems' => $faqItems->map(fn ($item) => $item->content),
            'officeHours' => $officeHours ? $officeHours->content : null,
            'cta' => $cta ? $cta->content : null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ], TurnstileService::rules()));

        try {
            // Save submission to database
            $submission = ContactSubmission::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'subject' => $validated['subject'],
                'message' => $validated['message'],
                'status' => 'new',
            ]);

            // Send email notification
            $adminEmail = config('mail.from.address', 'support@believeinunity.org');

            Mail::send([], [], function ($message) use ($validated, $adminEmail) {
                $message->to($adminEmail)
                    ->subject("Contact Form: {$validated['subject']}")
                    ->html("
                            <h2>New Contact Form Submission</h2>
                            <p><strong>Name:</strong> {$validated['first_name']} {$validated['last_name']}</p>
                            <p><strong>Email:</strong> {$validated['email']}</p>
                            <p><strong>Subject:</strong> {$validated['subject']}</p>
                            <p><strong>Message:</strong></p>
                            <p>".nl2br(e($validated['message'])).'</p>
                        ');
            });

            Log::info('Contact form submitted', [
                'submission_id' => $submission->id,
                'email' => $validated['email'],
                'subject' => $validated['subject'],
            ]);

            return back()->with('success', true);
        } catch (\Exception $e) {
            Log::error('Failed to send contact form email', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withErrors([
                'message' => 'Failed to send your message. Please try again later.',
            ]);
        }
    }
}
