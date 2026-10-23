<?php

namespace Filament\ChatAi\Agent;

use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\StreamChunk;
use Filament\ChatAi\Models\Conversation;
use Illuminate\Support\Str;
use Throwable;

/**
 * Names a conversation from its first exchange, the way a chat app does: a few
 * words that say what it is about, instead of the question cut mid-sentence.
 *
 * Asked once, right after the first answer, with no tools and a small budget.
 * If the model is slow, unavailable or answers with something unusable, the
 * conversation simply keeps the title it already had.
 */
final class ConversationTitle
{
    /** Short enough to read in a sidebar; the model is asked for less. */
    public const MAX_WORDS = 6;

    /** A few words need few tokens, but a reasoning model thinks first. */
    public const OUTPUT_TOKENS = 200;

    public static function enabled(): bool
    {
        return (bool) config('chatai.conversation_title', true);
    }

    /**
     * Returns the new title, or null when the conversation keeps the one it has.
     */
    public static function write(Conversation $conversation, string $question, string $answer, LlmConfig $config, LlmClient $llm): ?string
    {
        if (! self::enabled() || $conversation->messages()->where('role', 'user')->count() > 1) {
            return null; // only the first exchange names the conversation
        }

        // The tool editor's test chat is thrown away, and a turn the user stopped
        // is not worth another call.
        if (str_ends_with((string) $conversation->panel_id, ':playground')) {
            return null;
        }

        $asked = trim(Str::limit(Str::squish($question), 500, ''));
        $said = trim(Str::limit(Str::squish(strip_tags($answer)), 800, ''));

        if ($asked === '') {
            return null;
        }

        try {
            $text = '';

            foreach ($llm->stream(
                // Room to spare: a reasoning model spends tokens thinking before the
                // first word, and with a tight budget the answer comes back empty.
                $config->withOptions(null, self::OUTPUT_TOKENS, 0.0),
                [
                    ['role' => 'system', 'content' => 'You name chat conversations. Answer with a title of at most '.self::MAX_WORDS
                        .' words, in the language of the question, naming what it is about. No quotes, no final stop, no preamble, never the word "title".'],
                    ['role' => 'user', 'content' => "Question:\n{$asked}\n\nAnswer:\n{$said}"],
                ],
                [],
            ) as $chunk) {
                if ($chunk->type === StreamChunk::TEXT) {
                    $text .= $chunk->text;
                }
            }
        } catch (Throwable) {
            return null; // a title is never worth failing a turn for
        }

        $title = self::clean($text);

        if ($title === null) {
            return null;
        }

        $conversation->update(['title' => $title]);

        return $title;
    }

    /**
     * Models like to wrap a title in quotes, prefix it or explain themselves.
     */
    protected static function clean(string $text): ?string
    {
        $wrapping = " \t\n\r\0\x0B\"'«»“”‘’`*";

        // The quotes come off first: a prefix hides behind them ("Title: ...").
        $title = trim(Str::squish(strip_tags($text)), $wrapping);
        $title = preg_replace('/^(t[ií]tulo|title)\s*[:\-]\s*/iu', '', $title) ?? $title;
        $title = rtrim(trim($title, $wrapping), '.');

        // A model that ignored the instruction and wrote a sentence (or nothing).
        if ($title === '' || str_contains($title, "\n") || str_word_count($title, 0, 'àáâãçéêíóôõúüÀÁÂÃÇÉÊÍÓÔÕÚÜ0123456789') > self::MAX_WORDS + 2) {
            return null;
        }

        return Str::limit($title, Conversation::TITLE_LENGTH, '');
    }
}
