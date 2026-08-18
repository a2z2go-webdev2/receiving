const uploadStatusLabels: Record<string, string> = {
    staging: 'In Progress',
    queued: 'In Progress',
    processing: 'In Progress',
    completed: 'Completed',
    partial_failed: 'Partial Failed',
    failed: 'Failed',
};

const aiStatusLabels: Record<string, string> = {
    pending: 'Pending',
    processing: 'In Progress',
    extracted: 'Completed',
    partial_failed: 'Partial Failed',
    failed: 'Failed',
    manual_review: 'Manual Review',
};

export function uploadStatusLabel(status: string): string {
    return uploadStatusLabels[status] ?? friendlyStatus(status);
}

export function aiStatusLabel(status: string): string {
    return aiStatusLabels[status] ?? friendlyStatus(status);
}

function friendlyStatus(value: string): string {
    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
