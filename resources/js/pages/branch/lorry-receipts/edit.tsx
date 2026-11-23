import type { ComponentProps } from 'react';
import LrForm from './lr-form';

type Props = ComponentProps<typeof LrForm>;

export default function LorryReceiptEdit(props: Props) {
    return <LrForm {...props} mode="edit" />;
}
