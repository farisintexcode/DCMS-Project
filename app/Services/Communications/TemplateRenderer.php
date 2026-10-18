<?php

namespace App\Services\Communications;

use App\Models\CommunicationTemplate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Literal text substitution only; no Blade, PHP, HTML or expression evaluator. */
class TemplateRenderer
{
    private const TOKEN = '/\{\{\s*([a-z_]+)\s*\}\}/u';

    public function validate(array $input, bool $publishing = false): array
    {
        $data = Validator::make($input, ['subject' => ['present', 'nullable', 'string', 'max:200'], 'body' => ['present', 'nullable', 'string', 'max:20000'], 'required' => ['present', 'array', 'list', 'max:5'], 'required.*' => ['string', 'distinct']])->validate();
        $subject = trim($data['subject'] ?? '');
        $body = trim($data['body'] ?? '');
        if (preg_match('/[\r\n\x00]/u', $subject)) {
            throw ValidationException::withMessages(['subject' => 'Subject must be a single line.']);
        }
        if ($publishing && ($subject === '' || $body === '')) {
            throw ValidationException::withMessages(['body' => 'A nonblank subject and body are required before approval.']);
        }
        $tokens = [];
        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            preg_match_all(self::TOKEN, $text, $matches);
            $remainder = preg_replace(self::TOKEN, '', $text);
            if (str_contains($remainder, '{{') || str_contains($remainder, '}}') || str_contains($text, '{!!')) {
                throw ValidationException::withMessages([$field => 'Use literal text and approved {{placeholder}} tokens only.']);
            }
            foreach ($matches[1] as $token) {
                if (! array_key_exists($token, config('communications.placeholders'))) {
                    throw ValidationException::withMessages([$field => 'Unknown placeholder: '.$token.'.']);
                }
                $tokens[$token] = ['required' => in_array($token, $data['required'], true)];
            }
        }
        if (array_diff($data['required'], array_keys($tokens))) {
            throw ValidationException::withMessages(['required' => 'Required fields must be approved placeholders used in the saved subject or body.']);
        }
        ksort($tokens);

        return ['subject_template' => $subject, 'body_template' => $body, 'allowed_placeholders' => $tokens == [] ? (object) [] : $tokens];
    }

    public function render(CommunicationTemplate $template, array $values): array
    {
        if (trim($template->subject_template ?? '') === '' || trim($template->body_template) === '') {
            throw ValidationException::withMessages(['preview' => 'Save a subject and body before previewing this option.']);
        }
        $required = [];
        foreach ($template->allowed_placeholders as $key => $definition) {
            if (! is_array($definition) || ! is_bool($definition['required'] ?? null)) {
                throw ValidationException::withMessages(['preview' => 'Template placeholder requirements need review.']);
            }
            if ($definition['required']) {
                $required[] = $key;
            }
        }
        $content = $this->validate(['subject' => $template->subject_template, 'body' => $template->body_template, 'required' => $required]);
        $missing = [];
        $optional = [];
        foreach ((array) $content['allowed_placeholders'] as $key => $definition) {
            if (! isset($values[$key]) || trim((string) $values[$key]) === '') {
                if ($definition['required']) {
                    $missing[] = $key;
                } else {
                    $optional[] = $key;
                }
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(['preview' => 'Required source data unavailable: '.implode(', ', $missing).'. Select suitable context or review the template requirements.']);
        }
        $replace = fn (array $match): string => (string) ($values[$match[1]] ?? '');
        $subject = preg_replace_callback(self::TOKEN, $replace, $content['subject_template']);
        if (preg_match('/[\r\n\x00]/u', $subject)) {
            throw ValidationException::withMessages(['preview' => 'The selected source contains invalid subject line characters.']);
        }

        return ['subject' => $subject, 'body' => preg_replace_callback(self::TOKEN, $replace, $content['body_template']), 'missing_optional' => $optional];
    }
}
