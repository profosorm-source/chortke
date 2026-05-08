<?php

namespace App\Validators;

use Core\Validator;

class CryptoSubmitValidator extends Validator
{
    protected array $rules = [
        'intent_id' => 'required|numeric',
        'tx_hash' => 'required|string|min:10|max:120',
        'from_wallet' => 'required|string|min:10|max:120'
    ];

    protected array $messages = [
        'intent_id.required' => 'شناسه Intent الزامی است',
        'tx_hash.required' => 'هش تراکنش الزامی است',
        'from_wallet.required' => 'ولت مبدا الزامی است'
    ];
}