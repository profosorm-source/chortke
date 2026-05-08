<?php

namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════
 *  Listener Interface
 * ═══════════════════════════════════════════════════════════════
 */
interface Listener
{
    public function handle(Event $event): void;
}