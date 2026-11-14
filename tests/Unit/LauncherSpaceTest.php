<?php

it('reserves room for the floating launcher so it never sits on a control', function (): void {
    $css = file_get_contents(__DIR__.'/../../resources/dist/chatai.css');

    expect($css)
        ->toContain('--chatai-launcher-space: 7rem')
        ->toContain('body:has(.fi-chatai-floating .fi-chatai-launcher):not(:has(.fi-chatai-page)) .fi-main')
        ->toContain('padding-bottom: var(--chatai-launcher-space)')
        ->toContain('padding-inline-end: var(--chatai-launcher-space)')
        ->toContain('min-width: 44px')
        ->toContain('min-height: 44px');
});
