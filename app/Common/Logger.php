<?php

namespace App\Common;

class Logger {
    private static $logFile = 'webhook.log';

    public static function log($data) {
        $logData = array_merge([
            'time' => date('Y-m-d H:i:s')
        ], $data);

        file_put_contents(
            self::$logFile, 
            json_encode($logData, JSON_PRETTY_PRINT) . 
            "\n------------------------\n",
            FILE_APPEND
        );
    }
}
