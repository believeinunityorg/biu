<?php

return [
    'announcement_categories' => [
        'Event',
        'Resource',
        'Update',
        'News',
        'Alert',
        'General',
    ],

    'announcement_statuses' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'archived' => 'Archived',
        'hidden' => 'Hidden',
    ],

    'default_discussion_categories' => [
        'General',
        'Events',
        'Resources',
        'Support',
        'Ideas',
        'Announcements Discussion',
    ],

    'report_reasons' => [
        'spam' => 'Spam',
        'harassment' => 'Harassment',
        'hate_speech' => 'Hate Speech',
        'fraud' => 'Fraud or Scam',
        'inappropriate' => 'Inappropriate Content',
        'violence' => 'Violence or Threats',
        'copyright' => 'Copyright Violation',
        'adult_content' => 'Adult Content',
        'other' => 'Other',
    ],

    'reaction_emojis' => ['👍', '❤️', '🙏', '🎉', '😮', '😢', '🔥'],

    'default_settings' => [
        'allow_followers_to_post' => true,
        'allow_members_to_post' => true,
        'require_approval' => false,
        'allow_attachments' => true,
        'allow_mentions' => true,
        'enable_reactions' => true,
        'enable_comments' => true,
        'enable_reporting' => true,
        'auto_archive_days' => null,
    ],
];
