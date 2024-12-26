<?php

/*
Copyright (c) Manticore Software Ltd.

This file is part of the manticore-load tool and is licensed under the MIT License.
For full license details, see the LICENSE file in the project root.

Source code available at: https://github.com/manticoresoftware/manticore-load
*/

/**
 * Class ConsoleOutput
 * Provides thread-safe console output functionality using semaphores
 * to prevent output interleaving when multiple processes write simultaneously
 */
class ConsoleOutput {
    /** @var resource|null Semaphore resource for synchronizing output */
    private static $semaphore = null;
    
    /**
     * Initializes the output semaphore for thread-safe console writing
     * Creates a semaphore using the current file as the key source
     * Dies if semaphore creation fails
     * 
     * @return void
     */
    public static function init() {
        if (self::$semaphore === null) {
            // Generate a unique key based on this file
            $sem_key = ftok(__FILE__, 'o');
            // Create/get semaphore with read/write permissions
            self::$semaphore = sem_get($sem_key, 1, 0644, 1);
            if (self::$semaphore === false) {
                die("Failed to create output semaphore\n");
            }
        }
    }

    /**
     * Writes a message to STDOUT in a thread-safe manner
     * Automatically initializes the semaphore if not already done
     * 
     * @param string $message The message to write to console
     * @return void
     */
    public static function write($message) {
        if (self::$semaphore === null) {
            self::init();
        }

        // Lock the semaphore before writing
        sem_acquire(self::$semaphore);
        fwrite(STDOUT, $message);
        // Release the lock after writing
        sem_release(self::$semaphore);
    }

    /**
     * Writes a message to STDOUT followed by a newline
     * Convenience wrapper around write()
     * 
     * @param string $message The message to write to console
     * @return void
     */
    public static function writeLine($message) {
        self::write($message . "\n");
    }
} 