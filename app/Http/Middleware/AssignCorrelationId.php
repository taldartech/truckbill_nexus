<?php

namespace App\Http\Middleware;

use App\Support\Observability\CorrelationContext;
use App\Support\Observability\CorrelationId;
use App\Support\Observability\LogRedactor;
use App\Support\Tenant\TenantContextHolder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function __construct(
        private readonly LogRedactor $redactor = new LogRedactor,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = CorrelationId::tryNormalize(
            $request->headers->get(CorrelationId::HEADER)
        );

        CorrelationContext::set($correlationId);
        $request->attributes->set('correlation_id', $correlationId);

        $context = [
            'correlation_id' => $correlationId,
            'request_id' => $correlationId,
        ];

        if (TenantContextHolder::has()) {
            $tenant = TenantContextHolder::require();
            $context['tenant'] = [
                'company_id' => $tenant->companyId,
                'branch_id' => $tenant->branchId,
                'owner_id' => $tenant->ownerId,
            ];
        }

        Log::withContext($this->redactor->redact($context));

        try {
            /** @var Response $response */
            $response = $next($request);
            $response->headers->set(CorrelationId::HEADER, $correlationId);

            return $response;
        } finally {
            CorrelationContext::clear();
            Log::flushSharedContext();
        }
    }
}
