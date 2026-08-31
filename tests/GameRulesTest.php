<?php

declare(strict_types=1);

use Lottery\Games\Families\D5Rules;
use Lottery\Games\Families\K3Rules;
use Lottery\Games\Families\MotoRaceRules;
use Lottery\Games\Families\WinGoRules;

TestRunner::group('Game rules — WinGo');

$wingo = new WinGoRules();

$r0 = $wingo->build(0);
$r5 = $wingo->build(5);
$r3 = $wingo->build(3);
$r8 = $wingo->build(8);

TestRunner::equals('0 is red+violet', 'red,violet', $r0['color']);
TestRunner::equals('5 is green+violet', 'green,violet', $r5['color']);
TestRunner::equals('3 is green', 'green', $r3['color']);
TestRunner::equals('8 is red', 'red', $r8['color']);
TestRunner::equals('0 is small', 'small', $r0['size']);
TestRunner::equals('5 is big', 'big', $r5['size']);
TestRunner::equals('3 is odd', 'odd', $r3['parity']);

$hit = $wingo->evaluateSelection('number', '3', $r3);
TestRunner::ok('number hit pays 9x', $hit['won'] && $hit['odds'] === 9.0);
TestRunner::ok('number miss', !$wingo->evaluateSelection('number', '4', $r3)['won']);

$green = $wingo->evaluateSelection('color', 'green', $r3);
TestRunner::ok('pure green pays 2x', $green['won'] && $green['odds'] === 2.0);

$greenOn5 = $wingo->evaluateSelection('color', 'green', $r5);
TestRunner::ok('green on 5 pays 1.5x', $greenOn5['won'] && $greenOn5['odds'] === 1.5);

$redOn0 = $wingo->evaluateSelection('color', 'red', $r0);
TestRunner::ok('red on 0 pays 1.5x', $redOn0['won'] && $redOn0['odds'] === 1.5);

$violet = $wingo->evaluateSelection('color', 'violet', $r0);
TestRunner::ok('violet on 0 pays 4.5x', $violet['won'] && $violet['odds'] === 4.5);
TestRunner::ok('violet loses on 3', !$wingo->evaluateSelection('color', 'violet', $r3)['won']);
TestRunner::ok('green loses on 8', !$wingo->evaluateSelection('color', 'green', $r8)['won']);

TestRunner::equals('multi-selection units', 3, count($wingo->parseSelections('number', '1,2,3')));
TestRunner::equals('duplicate selections collapse', 2, count($wingo->parseSelections('number', '1,2,1')));
TestRunner::throws('invalid number rejected', static fn() => $wingo->parseSelections('number', '12'), 'Invalid selection');
TestRunner::throws('invalid colour rejected', static fn() => $wingo->parseSelections('color', 'blue'), 'Invalid selection');
TestRunner::throws('unknown bet type rejected', static fn() => $wingo->parseSelections('rocket', '1'), 'Unsupported bet type');
TestRunner::throws('empty content rejected', static fn() => $wingo->parseSelections('number', ' '), 'cannot be empty');

TestRunner::group('Game rules — K3');

$k3   = new K3Rules();
$dice = $k3->build([3, 3, 5]);

TestRunner::equals('sum computed', 11, $dice['sum']);
TestRunner::equals('11 is big', 'big', $dice['size']);
TestRunner::equals('11 is odd', 'odd', $dice['parity']);
TestRunner::ok('pair detected', $dice['pair'] === true && $dice['pairFace'] === 3);
TestRunner::ok('total 11 pays 7.68', $k3->evaluateSelection('total', '11', $dice)['odds'] === 7.68);
TestRunner::ok('total hit', $k3->evaluateSelection('total', '11', $dice)['won']);
TestRunner::ok('pair 3 wins', $k3->evaluateSelection('pair', '3', $dice)['won']);
TestRunner::ok('pair 5 loses', !$k3->evaluateSelection('pair', '5', $dice)['won']);
TestRunner::ok('two_different 3:5 wins', $k3->evaluateSelection('two_different', '3:5', $dice)['won']);
TestRunner::ok('two_different 1:2 loses', !$k3->evaluateSelection('two_different', '1:2', $dice)['won']);

$triple = $k3->build([4, 4, 4]);
TestRunner::ok('triple detected', $triple['triple'] === true);
TestRunner::ok('triple_any wins', $k3->evaluateSelection('triple_any', 'any', $triple)['won']);
TestRunner::ok('triple_exact 4 wins 207.36x', $k3->evaluateSelection('triple_exact', '4', $triple)['odds'] === 207.36);
TestRunner::ok('triple_exact 5 loses', !$k3->evaluateSelection('triple_exact', '5', $triple)['won']);

$mixed = $k3->build([1, 2, 3]);
TestRunner::ok('three_different exact match wins', $k3->evaluateSelection('three_different', '1:2:3', $mixed)['won']);
TestRunner::ok('three_different mismatch loses', !$k3->evaluateSelection('three_different', '1:2:4', $mixed)['won']);
TestRunner::ok('small sum 6', $mixed['size'] === 'small');
TestRunner::throws('K3 total out of range', static fn() => $k3->parseSelections('total', '19'), 'Invalid selection');

TestRunner::group('Game rules — D5 / 5D');

$d5     = new D5Rules();
$digits = $d5->build([1, 2, 3, 4, 5]);

TestRunner::equals('sum of digits', 15, $digits['sum']);
TestRunner::equals('code string', '12345', $digits['code']);
TestRunner::equals('position C digit', 3, $digits['positions']['c']['digit']);
TestRunner::equals('sum 15 is small', 'small', $digits['positions']['sum']['size']);
TestRunner::ok('a:1 wins 9x', $d5->evaluateSelection('number', 'a:1', $digits)['won']);
TestRunner::ok('e:5 big wins', $d5->evaluateSelection('size', 'e:big', $digits)['won']);
TestRunner::ok('a:small wins', $d5->evaluateSelection('size', 'a:small', $digits)['won']);
TestRunner::ok('sum:odd wins', $d5->evaluateSelection('parity', 'sum:odd', $digits)['won']);
TestRunner::ok('b:odd loses', !$d5->evaluateSelection('parity', 'b:odd', $digits)['won']);
TestRunner::throws('number on SUM rejected', static fn() => $d5->parseSelections('number', 'sum:3'), 'Invalid selection');
TestRunner::throws('bad position rejected', static fn() => $d5->parseSelections('size', 'z:big'), 'Invalid selection');
TestRunner::equals('multi position units', 2, count($d5->parseSelections('number', 'a:1,b:2')));

TestRunner::group('Game rules — MotoRace');

$moto = new MotoRaceRules();
$race = $moto->build([7, 2, 9, 1, 3, 4, 5, 6, 8, 10]);

TestRunner::equals('champion', 7, $race['champion']);
TestRunner::equals('podium', [7, 2, 9], $race['podium']);
TestRunner::equals('champion 7 is big', 'big', $race['size']);
TestRunner::ok('champion bet wins 9.4x', $moto->evaluateSelection('champion', '7', $race)['odds'] === 9.4);
TestRunner::ok('podium bet on 9 wins', $moto->evaluateSelection('podium', '9', $race)['won']);
TestRunner::ok('podium bet on 1 loses', !$moto->evaluateSelection('podium', '1', $race)['won']);
TestRunner::ok('parity odd wins', $moto->evaluateSelection('parity', 'odd', $race)['won']);

$fromOverride = $moto->fromOverride('4');
TestRunner::equals('single-number override sets champion', 4, $fromOverride['champion']);
TestRunner::equals('override ranking is complete', 10, count($fromOverride['ranking']));
