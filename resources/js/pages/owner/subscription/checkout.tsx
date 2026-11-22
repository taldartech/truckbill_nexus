import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    test_mode_banner: string;
    gateway: string;
    public_key: string | null;
    subscription: {
        id: string;
        status: string;
        discounted_annual_total: string;
        items: { name: string; module: string | null }[];
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
    links: {
        overview: string;
        verify: string;
        simulate: string;
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
    payment,
    prefill,
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
                description: `Test Mode · ${payment.purpose}`,
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

    return (
        <>
            <Head title={`Checkout · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Owner Panel · Checkout
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {company.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Scope: {ownerPanelScope}
                    </p>
                </div>
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
                <section className="grid gap-4 rounded-xl border border-border p-4 lg:grid-cols-2">
                    <div>
                        <h2 className="font-semibold">Order summary</h2>
                        <dl className="mt-3 space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Purpose
                                </dt>
                                <dd>{payment.purpose}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Gateway
                                </dt>
                                <dd>{gateway}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Order ID
                                </dt>
                                <dd className="font-mono text-xs">
                                    {payment.gateway_order_id || '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Payable
                                </dt>
                                <dd className="text-lg font-semibold">
                                    {payment.currency} {payment.amount}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div>
                        <h2 className="font-semibold">Contract items</h2>
                        <ul className="mt-3 space-y-2 text-sm">
                            {subscription.items.map((item, index) => (
                                <li key={`${item.name}-${index}`}>
                                    {item.name}
                                    {item.module ? ` (${item.module})` : ''}
                                </li>
                            ))}
                        </ul>
                        <p className="mt-4 text-sm text-muted-foreground">
                            Browser success is not trusted. The server verifies
                            the signature and captured payment status before
                            activation.
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
                    {gateway === 'fake' ? (
                        <Form action={links.simulate} method="post">
                            <input
                                type="hidden"
                                name="payment_intent_id"
                                value={payment.id}
                            />
                            <Button type="submit">
                                Simulate verified capture (fake gateway)
                            </Button>
                        </Form>
                    ) : null}
                    <Button asChild variant="outline">
                        <Link href={links.overview}>Cancel</Link>
                    </Button>
                </div>
                {gateway === 'razorpay_test' && !public_key ? (
                    <p className="text-sm text-muted-foreground">
                        Razorpay Test Mode credentials are incomplete. Set
                        RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, and
                        RAZORPAY_WEBHOOK_SECRET (Test Mode only).
                    </p>
                ) : null}
            </div>
        </>
    );
}
