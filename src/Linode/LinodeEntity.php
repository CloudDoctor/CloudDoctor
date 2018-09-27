<?php

namespace CloudDoctor\Linode;

use CloudDoctor\CloudDoctor;

class LinodeEntity
{
    private static $entitiesAvailable;

    static protected function getHttpRequest() : \CloudDoctor\Common\Request
    {
        return CloudDoctor::getRequester('linode');
    }

    public static function LabelToId(string $seek): ?string
    {
        foreach (self::listAvailable() as $id => $label) {
            if ($label == $seek) {
                return $id;
            }
        }
        return null;
    }

    static public function listAvailable()
    {
        $called = get_called_class();
        if (!isset(self::$entitiesAvailable[$called])) {
            self::$entitiesAvailable[$called] = [];
            $called = get_called_class();
            $entitiesResponse = self::getHttpRequest()->getJson($called::ENDPOINT);
            foreach ($entitiesResponse->data as $entity) {
                if (property_exists($entity, 'label')) {
                    self::$entitiesAvailable[$called][$entity->id] = $entity->label;
                } else {
                    self::$entitiesAvailable[$called][$entity->id] = $entity->id;
                }
            }
        }
        return self::$entitiesAvailable[$called];
    }

    static public function describeAvailable()
    {
        $called = get_called_class();
        self::$entitiesAvailable['described-' . $called] = [];
        $entitiesResponse = self::getHttpRequest()->getJson($called::ENDPOINT);
        foreach ($entitiesResponse->data as $entity) {
            self::$entitiesAvailable['described-' . $called][$entity->id] = $entity;
        }
        return self::$entitiesAvailable['described-' . $called];
    }
}