// The JSON the notifications endpoints return.

export type InboxItem = { id: string; title: string; body: string; read: boolean; at: string };

export type Inbox = {
    items: InboxItem[];
    nextCursor: string | null;
    previousCursor: string | null;
    filters: { unread: boolean; type: string | null; q: string | null };
    types: Record<string, string>;
    unreadCount: number;
};
