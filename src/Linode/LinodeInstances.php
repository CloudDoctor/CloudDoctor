<?php

namespace CloudDoctor\Linode;

use CloudDoctor\CloudDoctor;

class LinodeInstances extends LinodeEntity
{
    const ENDPOINT = '/linode/instances';

    static public function GetById(int $linodeId)
    {
        $request = CloudDoctor::getRequester('linode');
        $called = get_called_class();
        $entity = $request->getJson($called::ENDPOINT . "/{$linodeId}");
        return $entity;
    }
}