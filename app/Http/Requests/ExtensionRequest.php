<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Authorization will be handled by middleware
    }

    public function rules()
    {
        return [
            'pkey' => 'required|unique:ipphone,pkey,' . $this->route('extension'),
            'cluster' => 'required|exists:cluster,pkey',
            'macaddr' => 'nullable|regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
            'provision' => 'nullable|string',
            'device' => 'required|string|max:255',
            'location' => 'required|in:local,remote',
            'desc' => 'nullable|string|max:255',
            'active' => 'in:YES,NO',
            'callbackto' => 'in:desk,cell',
            'callerid' => 'integer|nullable',
            'cellphone' => 'integer|nullable',
            'celltwin' => 'in:ON,OFF',
            'devicerec' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
            'dvrvmail' => 'exists:ipphone,pkey|nullable',
            'protocol' => 'in:IPV4,IPV6',
            'provisionwith' => 'in:IP,FQDN',
            'sndcreds' => 'in:No,Once,Always',
            'transport' => 'in:udp,tcp,tls,wss',
            'vmailfwd' => 'email|nullable'
        ];
    }
}
