import LorryHireForm from './lorry-hire-form';
import type { LorryHireFormProps } from './lorry-hire-form';

export default function LorryHireCreate(props: LorryHireFormProps) {
    return <LorryHireForm {...props} mode="create" />;
}
