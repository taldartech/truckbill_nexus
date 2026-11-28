import LorryHireForm from './lorry-hire-form';
import type { LorryHireFormProps } from './lorry-hire-form';

export default function LorryHireEdit(props: LorryHireFormProps) {
    return <LorryHireForm {...props} mode="edit" />;
}
