<?php

Co\run(function () {
    $server = new Co\Http\Server("0.0.0.0", 9502, false);
    $server->handle('/websocket', function ($request, $ws) {
        $ws->upgrade();
        while (true) {
            $frame = $ws->recv();
            if ($frame === false) {
                echo "error : " . swoole_last_error() . "\n";
                break;
            } else if ($frame == '') {
                break;
            } else {
                if ($frame->data == "close") {
                    $ws->close();
                    return;
                }
                $ws->push("Hello {$frame->data}!");
                $ws->push("How are you, {$frame->data}?");
            }
        }
    });
    $server->start();
});