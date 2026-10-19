<?php

namespace Filament\ChatAi\Speech;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

trait SendsSpeechRequests
{
    /**
     * @param  callable(): Response  $request
     */
    protected function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new SpeechException('The speech service is unreachable.', 0);
        }

        // Status only: the provider body may echo the key.
        if ($response->failed()) {
            throw new SpeechException("The speech service failed (status {$response->status()}).", $response->status());
        }

        return $response;
    }
}
