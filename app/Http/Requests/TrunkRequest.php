<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrunkRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Authorization will be handled by middleware
    }

    public function rules()
    {
        return [
            'pkey' => 'required|unique:lineio,pkey,' . $this->route('trunk'),
            'cluster' => 'required|exists:cluster,pkey',
            'carrier' => 'required|exists:carrier,pkey',
            'active' => 'in:YES,NO',
            'alertinfo' => 'string|nullable',
            'callerid' => 'integer|nullable',
            'callprogress' => 'in:ON,OFF',
            'description' => 'string|max:255',
            'devicerec' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
            'disa' => 'in:DISA,CALLBACK|nullable',
            'disapass' => 'string|nullable|min:8',
            'host' => 'required|string|max:255',
            'inprefix' => 'integer|nullable',
            'match' => 'integer|nullable',
            'moh' => 'in:ON,OFF',
            'password' => ['nullable', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'peername' => 'required|string|max:255',
            'register' => 'string|nullable',
            'sipiaxpeer' => 'string|required',
            'sipiaxuser' => 'string|required',
            'swoclip' => 'in:YES,NO',
            'tag' => 'string|nullable',
            'transform' => ['regex:/^(\d+?:\d+?\s*)+$/', 'nullable'],
            'trunkname' => 'required|string|max:255',
            'username' => 'string|nullable',
            'z_updater' => 'string|nullable'
        ];
    }
}
