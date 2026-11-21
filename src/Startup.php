<?php
declare(strict_types=1);
/**
 * JVM launch settings shared between authenticated web input and the worker.
 * No shell execution: each validated line is a single proc_open argv entry.
 * Dangerous JVM facilities (agents, class loaders, flags that execute hooks)
 * are not exposed as editable startup options.
 */
function startup_arguments(string $value): array {
    if (strlen($value)>1800 || preg_match('/[^\x09\x0a\x0d\x20-\x7e]/',$value)) {
        throw new InvalidArgumentException('JVM options must be plain ASCII text, at most 1800 bytes.');
    }
    $lines=preg_split('/\r\n|\r|\n/',$value)?:[];
    if (count($lines)>16) throw new InvalidArgumentException('Use at most 16 lines of JVM options.');
    $options=[];
    foreach($lines as $line) {
        $flag=trim($line);
        if ($flag==='') continue;
        if (str_contains($flag,' ') || str_contains($flag,"\t") || strlen($flag)>160) {
            throw new InvalidArgumentException('Enter a single JVM flag per line, without spaces or shell quoting.');
        }
        $safe=
            preg_match('/^-XX:\+(?:UseG1GC|AlwaysPreTouch|ParallelRefProcEnabled|DisableExplicitGC|UseStringDeduplication)$/D',$flag) ||
            preg_match('/^-XX:(?:MaxGCPauseMillis|ActiveProcessorCount|ConcGCThreads|ParallelGCThreads|G1ReservePercent|InitiatingHeapOccupancyPercent)=([1-9][0-9]{0,3})$/D',$flag) ||
            preg_match('/^-XX:G1HeapRegionSize=(?:1|2|4|8|16|32)[mM]$/D',$flag) ||
            preg_match('/^-Xss(?:[1-9][0-9]{0,3})[kKmM]$/D',$flag) ||
            preg_match('/^-Dfile\.encoding=UTF-8$/D',$flag) ||
            preg_match('/^-Djava\.net\.preferIPv4Stack=(?:true|false)$/D',$flag) ||
            preg_match('/^-Djava\.awt\.headless=true$/D',$flag) ||
            preg_match('/^-Duser\.timezone=[A-Za-z][A-Za-z0-9_+\-\/]{0,63}$/D',$flag) ||
            $flag==='--enable-native-access=ALL-UNNAMED';
        if (!$safe) {
            throw new InvalidArgumentException('Unsupported JVM option: '.$flag.'. Only documented GC, CPU, thread, timezone and encoding flags are allowed. Configure heap separately.');
        }
        if (in_array($flag,$options,true)) throw new InvalidArgumentException('Duplicate JVM option: '.$flag);
        $options[]=$flag;
    }
    return $options;
}
function startup_settings(array $input,int $defaultMemory=2048): array {
    $min=filter_var($input['xms']??256,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>128,'max_range'=>32768]]);
    $max=filter_var($input['memory']??$defaultMemory,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>512,'max_range'=>32768]]);
    if ($min===false || $max===false || $min>$max) {
        throw new InvalidArgumentException('Initial Java heap must be 128-32768 MiB, maximum 512-32768 MiB, and initial cannot exceed maximum.');
    }
    $raw=$input['jvm_args']??'';
    if (!is_string($raw)) throw new InvalidArgumentException('Invalid JVM arguments.');
    $args=startup_arguments($raw);
    $native=$input['native_access']??'0';
    if (!in_array($native,['0','1',0,1,false,true],true)) {
        throw new InvalidArgumentException('Invalid native library access option.');
    }
    $netty=$input['netty_workdir']??'1';
    if (!in_array($netty,['0','1',0,1,false,true],true)) {
        throw new InvalidArgumentException('Invalid Netty native-library work directory setting.');
    }
    return [
        'xms'=>$min,
        'memory'=>$max,
        'jvm_args'=>implode("\n",$args),
        'native_access'=>(bool)$native,
        'netty_workdir'=>((string)(int)(bool)$netty)==='1'
    ];
}
