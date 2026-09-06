<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Illuminate\Support\Collection;
use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

final class NativeRequestGuard
{
    /**
     * Freeze the final post-middleware attachments, not just preparation's earlier inventory.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    public static function messages(array $messages, int $limit, string $driver, int $step): array
    {
        if ($step === 0 && (count($messages) !== 1 || ! reset($messages) instanceof UserMessage)) {
            throw ConfigurationException::make('unsupported_agent', 'Extraction requests must not contain conversation history.');
        }

        $bytes = 0;

        foreach ($messages as $key => $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }

            $attachments = [];

            foreach ($message->attachments as $attachment) {
                $remaining = max(0, $limit - $bytes);

                if ($attachment instanceof Base64Image) {
                    $encoded = preg_replace('/\s/', '', $attachment->base64) ?? '';

                    if (strlen($encoded) > (int) ceil($remaining / 3) * 4) {
                        throw self::oversized();
                    }

                    $content = base64_decode($encoded, true);
                } elseif ($attachment instanceof LocalImage && is_file($attachment->path)) {
                    $content = file_get_contents($attachment->path, false, null, 0, $remaining + 1);
                } else {
                    throw ConfigurationException::make(
                        'unsupported_attachment',
                        'Extraction middleware attachments must be local or inline images with bounded content.',
                    );
                }

                if ($content === false || $content === '') {
                    throw ConfigurationException::make('invalid_attachment', 'An extraction attachment has no valid image content.');
                }

                $bytes += strlen($content);

                if ($bytes > $limit) {
                    throw self::oversized();
                }

                $attachments[] = (new Base64Image(base64_encode($content), $attachment->mimeType()))
                    ->as($attachment->name())
                    ->withProviderOptions($attachment->providerOptions($driver));
            }

            $messages[$key] = clone $message;
            $messages[$key]->attachments = new Collection($attachments);
        }

        return $messages;
    }

    private static function oversized(): AiExecutionException
    {
        return AiExecutionException::make(
            'input_too_large_for_model',
            'Final visual attachments exceed the configured pre-base64 AI request limit.',
        );
    }
}
