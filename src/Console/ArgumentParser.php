<?php

declare(strict_types=1);

namespace Bridge\Console;

use InvalidArgumentException;

final class ArgumentParser
{
    private const ARITY = [
        'init'       => 0,
        'link'       => 3,
        'sync'       => 0,
        'status'     => 0,
        'inspect'    => 1,
        'resolve'    => 2,
        'approve'    => 3,
        'retry'      => 1,
        'metrics'    => 0,
        'local-edit' => 4,
        'intake'     => 0,
        'escalations' => 0,
        'sync-notes' => 0,
        'notes' => 0,
        'sync-comments' => 0,
        'comments' => 0,
    ];

    public function parse(array $arguments): Arguments
    {
        $mode = $home = null;
        $values = [];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--mode=')) {
                $mode = substr($argument, 7);
            } elseif (str_starts_with($argument, '--home=')) {
                $home = substr($argument, 7);
            } elseif (str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('Unknown option. Use help for syntax.');
            } else {
                $values[] = $argument;
            }
        }
        $command = array_shift($values) ?? 'help';
        if ($command !== 'help' && (!isset(self::ARITY[$command]) || count($values) !== self::ARITY[$command])) {
            throw new InvalidArgumentException('Invalid command or arguments. Run: php bin/bridge.php help');
        }
        return new Arguments($command, $values, $mode, $home);
    }
}
