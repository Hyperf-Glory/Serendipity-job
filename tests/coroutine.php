<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

use Swow\Coroutine;

//$a = 1;
//$coroutine = Coroutine::run(function () use ($a) {
//    try {
//        \Swow\defer(function () {
////            echo Coroutine::getCurrent()
////                ->getId() . '已退出.' . PHP_EOL;
//        });
//
//        (file_get_contents('http://www.baidu.com'));
//    } catch (Throwable $e) {
//    }
//});
//$data = [
//    'state' => $coroutine?->getStateName(),
//    'trace_list' => json_encode($coroutine?->getTrace(), JSON_THROW_ON_ERROR),
//    'executed_file_name' => $coroutine?->getExecutedFilename(),
//    'executed_function_name' => $coroutine?->getExecutedFunctionName(),
//    'executed_function_line' => $coroutine?->getExecutedLineno(),
//    'vars' => $coroutine?->getDefinedVars(),
//    'round' => $coroutine?->getRound(),
//    'elapsed' => $coroutine?->getElapsed(),
//];
//echo json_encode([
//    'code' => 0,
//    'msg' => 'ok!',
//    'data' => $data,
//], JSON_THROW_ON_ERROR);

$coroutine = Coroutine::run(function ($a) {
    $b = Coroutine::yield();

    return $a . ' ' . $b;
}, 'hello');
echo $coroutine->resume('world') . ' #' . PHP_EOL;
