<?php

namespace App\Enums;

enum WhatsAppConnectionMode: string
{
    case CloudApi = 'cloud_api';
    case Coexistence = 'coexistence';
}
