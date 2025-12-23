<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;
use App\Services\RecordNormalizer;

class TestContactExport extends Command
{
    protected $signature = 'test:contact-export';
    protected $description = 'Test contact data normalization and CSV export';

    public function handle()
    {
        $this->info("Testing contact data normalization...\n");

        // Get a sample contact
        $contact = Contact::first();
        
        if (!$contact) {
            $this->error("No contacts found in database!");
            return 1;
        }

        $rawData = $contact->toArray();
        $this->info("Raw Contact Data:");
        $this->line(json_encode($rawData, JSON_PRETTY_PRINT));
        
        $this->line("\n" . str_repeat("=", 80) . "\n");

        // Normalize the contact
        $normalized = RecordNormalizer::normalizeContact($rawData);
        
        $this->info("Normalized Contact Data:");
        $this->line(json_encode($normalized, JSON_PRETTY_PRINT));
        
        $this->line("\n" . str_repeat("=", 80) . "\n");

        // Check key fields
        $fieldsToCheck = [
            'first_name', 'last_name', 'title', 'work_email', 'personal_email',
            'mobile_number', 'direct_number', 'city', 'state', 'country',
            'seniority', 'departments'
        ];

        $this->info("Field Check:");
        foreach ($fieldsToCheck as $field) {
            $value = $normalized[$field] ?? 'MISSING';
            $status = $value && $value !== 'MISSING' ? '✓' : '✗';
            $display = is_array($value) ? json_encode($value) : (string)$value;
            $this->line("$status $field: " . ($display ?: '(empty)'));
        }

        return 0;
    }
}
