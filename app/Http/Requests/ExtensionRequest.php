<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtensionRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Authorization will be handled by middleware
    }

    public function rules()
    {
        $extension = $this->route('extension');
        $pkeySubmitted = $this->input('pkey');

        // On update when pkey is unchanged, skip unique check (e.g. only toggling Active)
        $pkeyUnchanged = $extension instanceof \App\Models\Extension
            && (string) $pkeySubmitted === (string) $extension->getAttribute('pkey');

        if ($pkeyUnchanged) {
            $pkeyRule = 'required';
        } else {
            $cluster = $this->input('cluster');
            $pkeyRule = Rule::unique('ipphone', 'pkey')->where('cluster', $cluster);
            if ($extension instanceof \App\Models\Extension) {
                $pkeyRule->ignore($extension->getKey(), 'id');
            }
        }

        return [
            'pkey' => ['required', $pkeyRule],
            'cluster' => 'required|exists:cluster,pkey',
            'macaddr' => 'nullable|regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
            'device' => 'required|string|max:255',
            'desc' => 'nullable|string|max:255',
            'active' => 'in:YES,NO',
            'callbackto' => 'in:desk,cell',
            'callerid' => 'integer|nullable',
            'cellphone' => 'integer|nullable',
            'celltwin' => 'in:ON,OFF',
            'devicerec' => 'in:default,None,OTR,OTRR,Inbound.Outbound,Both',
            'dvrvmail' => 'exists:ipphone,pkey|nullable',
            'protocol' => 'in:IPV4,IPV6',
            'transport' => 'in:udp,tcp,tls,wss',
            'vmailfwd' => 'email|nullable'
        ];
    }
}
