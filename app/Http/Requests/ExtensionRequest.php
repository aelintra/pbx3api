<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @deprecated Extension update now uses Request + Validator in ExtensionController (see PLAN_MODELS_AND_VALIDATION_HARMONISATION.md Task 2). This class is no longer used by any route; kept for reference only.
 */
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
            // Uniqueness is per cluster; DB stores cluster shortuid
            $clusterShortuid = cluster_identifier_to_shortuid($this->input('cluster'));
            $pkeyRule = Rule::unique('ipphone', 'pkey')->where('cluster', $clusterShortuid ?? $this->input('cluster'));
            if ($extension instanceof \App\Models\Extension) {
                $pkeyRule->ignore($extension->getKey(), 'id');
            }
        }

        return [
            'pkey' => ['required', $pkeyRule],
            'cluster' => 'required|exists:cluster,pkey',
            'macaddr' => ['nullable', 'regex:/^(?:[0-9a-fA-F]{12}|([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2})$/'],
            'device' => 'nullable|string|max:255',
            'desc' => 'nullable|string|max:255',
            'cname' => 'string|nullable',
            'description' => 'string|nullable',
            'active' => 'in:YES,NO',
            'callbackto' => 'in:desk,cell',
            'callerid' => 'string|nullable',
            'callmax' => 'integer|nullable',
            'cellphone' => 'string|nullable',
            'celltwin' => 'in:ON,OFF',
            'devicerec' => 'in:default,None,Inbound,Outbound,Both',
            'dvrvmail' => 'exists:ipphone,pkey|nullable',
            'extalert' => 'string|nullable',
            'provision' => 'string|nullable',
            'provisionwith' => 'in:IP,FQDN',
            'pjsipuser' => 'string|nullable',
            'technology' => 'nullable|in:SIP,IAX2,DiD,CLiD,Class',
            'protocol' => 'in:IPV4,IPV6',
            'transport' => 'in:udp,tcp,tls,wss',
            'vmailfwd' => 'email|nullable'
        ];
    }
}
