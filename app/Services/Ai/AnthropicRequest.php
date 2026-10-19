<?php

namespace App\Services\Ai;

/**
 * The fields every Messages API request shares, kept in one place.
 *
 * Claude Sonnet 5.5 refuses a forced tool_choice ("tool" or "any") with a 400,
 * so a writer that must answer through one tool offers it with tool_choice
 * "auto" and says so in its prompt; a reply without the tool counts as a
 * failure and is tried again. Adaptive thinking is on by default on that
 * model; FLEXEE_AI_THINKING (default between_tools, its lowest setting) keeps
 * these short writing jobs quick and cheap. Set it empty for a model that
 * doesn't take the setting.
 */
final class AnthropicRequest
{
    /** @return array<string, mixed> */
    public static function base(): array
    {
        $body = [
            'model' => config('flexee.ai.model'),
            'max_tokens' => (int) config('flexee.ai.max_tokens', 4000),
        ];
        $thinking = trim((string) config('flexee.ai.thinking'));
        if ($thinking !== '') {
            $body['thinking'] = ['type' => $thinking];
        }

        return $body;
    }

    /**
     * One tool the reply must use.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function answerWith(string $name, string $description, array $schema): array
    {
        return [
            'tools' => [['name' => $name, 'description' => $description, 'input_schema' => $schema]],
            'tool_choice' => ['type' => 'auto'],
        ];
    }

    /** The prompt line that takes the place of a forced tool_choice. */
    public static function only(string $tool): string
    {
        return "Answer only by calling the {$tool} tool, once, with every field filled. Don't reply in text.";
    }
}
