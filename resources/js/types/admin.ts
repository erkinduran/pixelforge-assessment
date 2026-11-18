// The admin report as the server sends it. Every money value is a formatted string ("$0.2648875").
export type ReportUserRow = {
    user_id: number | null;
    label: string;
    charges: number;
    provider_cost: string;
    charged: string;
    profit: string;
};

export type ReportOurs = {
    charges: number;
    provider_cost: string;
    charged: string;
    profit: string;
};

export type ReportPixelforge = { generations: number; cost: string };

export type ReportDay = {
    date: string;
    users: ReportUserRow[];
    ours: ReportOurs;
    pixelforge: ReportPixelforge | null;
    difference: string | null;
    pending: number;
    unknown_outcome: number;
};

export type UsageReport = {
    from: string;
    to: string;
    days: ReportDay[];
    totals: {
        ours: ReportOurs;
        pixelforge: ReportPixelforge | null;
        difference: string | null;
        pending: number;
        unknown_outcome: number;
    };
    pixelforge_error: string | null;
};
