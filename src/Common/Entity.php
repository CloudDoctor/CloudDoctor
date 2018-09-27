<?php

namespace CloudDoctor\Common;

class Entity
{
    static public function Factory()
    {
        $class = get_called_class();
        return new $class();
    }
}