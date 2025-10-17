<?php

namespace Carlosupreme\CEPQueryPayment\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array|null queryPayment(array $formData, array $options = [])
 * @method static array getBankOptions()
 * @method static string|null getBankCodeByName(string $bankName)
 * @method static string formatDate($date)
 *
 * @see \Carlosupreme\CEPQueryPayment\CEPQueryService
 */
class CEPQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Carlosupreme\CEPQueryPayment\CEPQueryService::class;
    }
}
