# Тестировние скорости array_map

Данный тест я решил написать, потому что решил проверить, что быстрее **for**, **foreach** или **array_map**.

**Результаты:**

>Test foreach 1000: **0.025344806713062** microseconds

>Test array_map 1000: **0.040612492766175** microseconds

>Test for 1000: **0.037093819676341** microseconds

Из теста видим, что array_map проигрывает *for* и *foreach* более чем  в 1,5 раза

# Как проводился тест
Дано ``php -v``
```
PHP 5.5.28 (cli) (built: Aug 27 2015 23:43:36)
Copyright (c) 1997-2015 The PHP Group
Zend Engine v2.5.0, Copyright (c) 1998-2015 Zend Technologies
```

Сам скрипт тестирования

```
<?php
    global $aHash;
    $b = [];
    $i = 0;
    $tmp = '';
    while($i < 5) {
        $tmp .= 'a';
        ++$i;
    }
    $aHash = array_fill(0, 100000, $tmp);
    unset($i, $tmp);

/*
 *  Test foreach;
 */

    function  Test1() {
        global $aHash;
        $t = microtime(true);
        reset($aHash);
        foreach($aHash as $val) {
            $b[] = 'c-'.$val;
        }
        return (microtime(true) - $t);
    }

/*
 *  Test array_map;
 */
    function  Test2() {
        global $aHash;
        $t = microtime(true);
        reset($aHash);
        $b = array_map(function($v) { return 'c-'.$v; }, $aHash);
        return (microtime(true) - $t);
    }

/*
 *  Test for;
 */
    function  Test3() {
        global $aHash;
        $t = microtime(true);
        reset($aHash);
        $size = count($aHash);
        for ($i=0; $i<$size; $i++) {
            $b[] = 'c-'.$aHash[$i];
        }
        return (microtime(true) - $t);
    }

    function clearTest() {
        global $aHash;
        unset($aHash);
    }

    $all = [];
    for ($i=0; $i<=1000; $i++) {
        $all[] = Test1();
        clearTest();
    }
    echo "Test foreach 1000: " . array_sum($all)/count($all). " microseconds \n";
    unset($all);

    $all = [];
    for ($i=0; $i<=1000; $i++) {
        $all[] = Test2();
        clearTest();
    }
    echo "Test array_map 1000: " . array_sum($all)/count($all). " microseconds \n";
    unset($all);

    $all = [];
    for ($i=0; $i<=1000; $i++) {
        $all[] = Test3();
        clearTest();
    }
    echo "Test for 1000: " . array_sum($all)/count($all). " microseconds \n";
    unset($all);

?>
```
Что бы скорректировань погрешность решено было выполнять по 1000 раз каждого теста, запускалось все в консоли.

