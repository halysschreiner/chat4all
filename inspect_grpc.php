<?php
$reflector = new ReflectionClass('Grpc\Server');
foreach ($reflector->getMethods() as $method) {
    echo $method->getName() . "\n";
}
