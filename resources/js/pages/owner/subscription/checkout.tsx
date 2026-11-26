import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';

type QuoteLineItem = {
    description?: string;
    name?: string;
    amount?: string;
};

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    test_mode_banner: string;
    gateway: string;
    public_key: string | null;
    subscription: null | {
        id: string;
        status: string;
        discounted_annual_total: string;
        items: { name: string; module: string | null }[];
    };
    quote?: null | {
        tenure_label?: string;
        service_period_start?: string;
        service_period_end?: string;
        common_renewal_date?: string;
        line_items?: QuoteLineItem[];
        subtotal_amount?: string;
        discount_amount?: string;
        taxable_amount?: string;
        gst?: {
            configured?: boolean;
            rate_percent?: string;
            cgst_amount?: string;
            sgst_amount?: string;
            igst_amount?: string;
            is_inter_state?: boolean;
        };
        total_payable?: string;
    };
    customerBilling?: {
        legal_name?: string;
        registered_address?: string | null;
        city?: string | null;
        state?: string | null;
        pin_code?: string | null;
        gstin?: string | null;
        pan?: string | null;
    };
    payment: {
        id: string;
        purpose: string;
        amount: string;
        currency: string;
        status: string;
        gateway_order_id: string | null;
    };
    prefill: { name: string; email: string };
    allow_fake_simulate?: boolean;
    links: {
        overview: string;
        verify: string;
        simulate: string | null;
    };
};

type RazorpayInstance = {
    open: () => void;
    on: (
        event: string,
        handler: (response: Record<string, unknown>) => void,
    ) => void;
};

declare global {
    interface Window {
        Razorpay?: new (options: Record<string, unknown>) => RazorpayInstance;
    }
}

export default function SubscriptionCheckout({
    company,
    ownerPanelScope,
    test_mode_banner,
    gateway,
    public_key,
    subscription,
    quote,
    customerBilling,
    payment,
    prefill,
    allow_fake_simulate = false,
    links,
}: Props) {
    const { errors } = usePage<{ errors?: { checkout?: string } }>().props;
    const [busy, setBusy] = useState(false);
    const [scriptReady, setScriptReady] = useState(false);
    const [scriptError, setScriptError] = useState<string | null>(null);
    const [localError, setLocalError] = useState<string | null>(null);
    const [pendingVerify, setPendingVerify] = useState(false);
    const pendingVerifyRef = useRef(false);

    useEffect(() => {
        if (gateway !== 'razorpay_test' || !public_key) {
            return;
        }

        const existing = document.querySelector(
            'script[data-razorpay-checkout]',
        ) as HTMLScriptElement | null;

        if (existing) {
            if (window.Razorpay) {
                setScriptReady(true);
            } else {
                existing.addEventListener('load', () => setScriptReady(true));
                existing.addEventListener('error', () =>
                    setScriptError(
                        'Unable to load Razorpay Checkout. Check your network and retry.',
                    ),
                );
            }
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://checkout.razorpay.com/v1/checkout.js';
        script.async = true;
        script.dataset.razorpayCheckout = '1';
        script.onload = () => setScriptReady(true);
        script.onerror = () =>
            setScriptError(
                'Unable to load Razorpay Checkout. Check your network and retry.',
            );
        document.body.appendChild(script);
    }, [gateway, public_key]);

    const openRazorpay = () => {
        setLocalError(null);

        if (!window.Razorpay) {
            setLocalError(
                'Razorpay Checkout is not ready yet. Wait a moment and try again.',
            );
            return;
        }

        if (!public_key || !payment.gateway_order_id) {
            setLocalError(
                'Checkout is not ready. Refresh the page to create a new Test Mode order.',
            );
            return;
        }

        if (payment.gateway_order_id.startsWith('order_fake_')) {
            setLocalError(
                'This page still references a simulated order. Refresh checkout to create a Razorpay Test Mode order.',
            );
            return;
        }

        setBusy(true);

        try {
            const rzp = new window.Razorpay({
                key: public_key,
                currency: payment.currency,
                name: 'TruckBill',
                description: `Subscription · ${payment.purpose}`,
                order_id: payment.gateway_order_id,
                prefill: {
                    name: prefill.name,
                    email: prefill.email,
                },
                notes: {
                    payment_intent_id: payment.id,
                    company_id: company.id,
                },
                theme: { color: '#0f766e' },
                handler(response: {
                    razorpay_order_id: string;
                    razorpay_payment_id: string;
                    razorpay_signature: string;
                }) {
                    pendingVerifyRef.current = true;
                    setPendingVerify(true);
                    setLocalError(null);
                    router.post(
                        links.verify,
                        {
                            payment_intent_id: payment.id,
                            razorpay_order_id: response.razorpay_order_id,
                            razorpay_payment_id: response.razorpay_payment_id,
                            razorpay_signature: response.razorpay_signature,
                        },
                        {
                            onError: () => {
                                pendingVerifyRef.current = false;
                                setPendingVerify(false);
                                setBusy(false);
                                setLocalError(
                                    'Payment could not be verified on the server. If money was not captured, you can retry safely.',
                                );
                            },
                            onFinish: () => {
                                setBusy(false);
                            },
                        },
                    );
                },
                modal: {
                    ondismiss() {
                        if (!pendingVerifyRef.current) {
                            setBusy(false);
                            setLocalError(
                                'Checkout was cancelled. No payment was recorded.',
                            );
                        }
                    },
                },
            });

            rzp.on('payment.failed', (response) => {
                setBusy(false);
                pendingVerifyRef.current = false;
                setPendingVerify(false);
                const description =
                    typeof response?.error === 'object' &&
                    response.error !== null &&
                    'description' in response.error &&
                    typeof (response.error as { description?: unknown })
                        .description === 'string'
                        ? (response.error as { description: string })
                              .description
                        : 'The Test Mode payment did not complete.';
                setLocalError(description);
            });

            rzp.open();
        } catch {
            setBusy(false);
            setLocalError(
                'Unable to open Razorpay Checkout. Refresh and try again.',
            );
        }
    };

    const alertMessage = errors?.checkout || scriptError || localError;
    const lineItems =
        quote?.line_items && quote.line_items.length > 0
            ? quote.line_items
            : (subscription?.items ?? []).map((item) => ({
                  name: item.name,
                  description: `${item.name}${item.module ? ` (${item.module})` : ''}`,
                  amount: undefined,
              }));

    return (
        <>
            <Head title={`Review & Pay · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Review & Pay"
            >
                <p
                    role="status"
                    className="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm font-medium"
                >
                    {test_mode_banner}
                </p>
                {pendingVerify ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border px-4 py-3 text-sm"
                    >
                        Verifying payment with the server. Do not close this
                        page…
                    </p>
                ) : null}
                {alertMessage ? (
                    <p className="text-sm text-destructive" role="alert">
                        {alertMessage}
                    </p>
                ) : null}

                <section className="grid gap-4 rounded-xl border border-border bg-card p-4 lg:grid-cols-2">
                    <div className="space-y-3">
                        <h2 className="font-semibold">Order & Tax Summary</h2>
                        <dl className="space-y-1.5 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Purpose
                                </dt>
                                <dd className="capitalize">
                                    {payment.purpose.replaceAll('_', ' ')}
                                </dd>
                            </div>
                            {quote?.tenure_label ? (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Period
                                    </dt>
                                    <dd>{quote.tenure_label}</dd>
                                </div>
                            ) : null}
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Order ID
                                </dt>
                                <dd className="font-mono text-xs">
                                    {payment.gateway_order_id || '—'}
                                </dd>
                            </div>
                            {quote?.taxable_amount ? (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Taxable Value
                                    </dt>
                                    <dd>
                                        {payment.currency}{' '}
                                        {quote.taxable_amount}
                                    </dd>
                                </div>
                            ) : null}
                            {quote?.gst?.configured ? (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        GST ({quote.gst.rate_percent}%)
                                    </dt>
                                    <dd>
                                        {quote.gst.is_inter_state
                                            ? `IGST ₹${quote.gst.igst_amount}`
                                            : `CGST ₹${quote.gst.cgst_amount} + SGST ₹${quote.gst.sgst_amount}`}
                                    </dd>
                                </div>
                            ) : null}
                            <div className="flex justify-between gap-4 border-t border-border pt-2">
                                <dt className="font-semibold">Total Payable</dt>
                                <dd className="text-lg font-bold">
                                    {payment.currency} {payment.amount}
                                </dd>
                            </div>
                        </dl>

                        {customerBilling?.legal_name ? (
                            <div className="rounded-lg border border-border/80 bg-muted/20 p-3 text-xs">
                                <p className="font-semibold">
                                    Billed To: {customerBilling.legal_name}
                                </p>
                                <p className="text-muted-foreground">
                                    {[
                                        customerBilling.registered_address,
                                        customerBilling.city,
                                        customerBilling.state,
                                        customerBilling.pin_code,
                                    ]
                                        .filter(Boolean)
                                        .join(', ') ||
                                        'Registered office not set'}
                                </p>
                                <p className="font-mono text-muted-foreground">
                                    GSTIN:{' '}
                                    {customerBilling.gstin || 'Unregistered'}
                                    {customerBilling.pan
                                        ? ` · PAN: ${customerBilling.pan}`
                                        : ''}
                                </p>
                            </div>
                        ) : null}
                    </div>

                    <div className="space-y-3">
                        <h2 className="font-semibold">Subscription Items</h2>
                        <ul className="space-y-2 text-sm">
                            {lineItems.map((item, index) => (
                                <li
                                    key={`${item.description || item.name}-${index}`}
                                    className="flex justify-between gap-2 border-b border-border/60 pb-1.5"
                                >
                                    <span>{item.description || item.name}</span>
                                    {item.amount ? (
                                        <span className="font-medium">
                                            ₹{item.amount}
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-muted-foreground">
                            Entitlements activate and your immutable GST tax
                            invoice is generated only after authoritative server
                            verification.
                        </p>
                    </div>
                </section>

                <div className="flex flex-wrap gap-3">
                    {gateway === 'razorpay_test' && public_key ? (
                        <Button
                            type="button"
                            disabled={
                                busy ||
                                pendingVerify ||
                                !scriptReady ||
                                Boolean(scriptError)
                            }
                            onClick={openRazorpay}
                        >
                            {pendingVerify
                                ? 'Verifying…'
                                : scriptReady
                                  ? 'Pay with Razorpay Test Mode'
                                  : 'Loading checkout…'}
                        </Button>
                    ) : null}
                    {gateway === 'fake' &&
                    allow_fake_simulate &&
                    links.simulate ? (
                        <Form action={links.simulate} method="post">
                            <input
                                type="hidden"
                                name="payment_intent_id"
                                value={payment.id}
                            />
                            <Button type="submit">
                                Simulate Verified Capture
                            </Button>
                        </Form>
                    ) : null}
                    <Button asChild variant="outline">
                        <Link href={links.overview}>Back to Purchase</Link>
                    </Button>
                </div>
            </CompanyOwnerPageShell>
        </>
    );
}
