import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { Spinner } from '@/components/ui/spinner';

type ButtonVariant = ComponentProps<typeof Button>['variant'];
type ButtonSize = ComponentProps<typeof Button>['size'];

type AppIconActionProps = {
    icon: LucideIcon;
    label: string;
    tooltip?: string;
    href?: string;
    onClick?: () => void;
    type?: 'button' | 'submit' | 'reset';
    variant?: ButtonVariant;
    size?: ButtonSize;
    disabled?: boolean;
    loading?: boolean;
    className?: string;
    /** When false, render nothing (permission-aware). */
    visible?: boolean;
    children?: ReactNode;
};

/**
 * Accessible icon-only (or icon+text) action control with tooltip + aria-label.
 */
export function AppIconAction({
    icon: Icon,
    label,
    tooltip,
    href,
    onClick,
    type = 'button',
    variant = 'outline',
    size = 'icon',
    disabled = false,
    loading = false,
    className,
    visible = true,
    children,
}: AppIconActionProps) {
    if (!visible) {
        return null;
    }

    const tip = tooltip ?? label;
    const content = (
        <>
            {loading ? (
                <Spinner className="size-4" />
            ) : (
                <Icon className="size-4" aria-hidden />
            )}
            {children}
            <span className="sr-only">{label}</span>
        </>
    );

    const control = href ? (
        <Button
            asChild
            variant={variant}
            size={size}
            disabled={disabled || loading}
            className={cn('shrink-0', className)}
        >
            <Link href={href} aria-label={label} prefetch>
                {content}
            </Link>
        </Button>
    ) : (
        <Button
            type={type}
            variant={variant}
            size={size}
            disabled={disabled || loading}
            onClick={onClick}
            aria-label={label}
            className={cn('shrink-0', className)}
        >
            {content}
        </Button>
    );

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>{control}</TooltipTrigger>
                <TooltipContent side="top">{tip}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
