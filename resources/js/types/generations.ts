// What the server sends about a generation. Money is always a formatted string
// ("$0.46734375"); the browser never does arithmetic on it.
export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_image_urls: string[];
    status: GenerationStatus;
    created_at: string | null;
    completed_at: string | null;
    estimate: { min: string; max: string };
    price: string | null;
    failure_message: string | null;
    image_url: string | null;
    image_expired: boolean;
};

export type TokenCounts = {
    text: number;
    image: number;
    output: number;
    reasoning: number;
};

export type GenerationEstimate = {
    min_price: string;
    max_price: string;
    tokens: { min: TokenCounts; max: TokenCounts };
};

export type PaginatedGenerations = {
    data: Generation[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
};

export function isFinalStatus(status: GenerationStatus): boolean {
    return status === 'succeeded' || status === 'failed';
}
