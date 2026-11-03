import { inject, type InjectionKey, type Ref } from 'vue';
import type { Attribution, Comment, DiscussionSettings, SubjectRef } from '../types';

export interface DiscussionContext {
    subject: SubjectRef;
    settings: Ref<DiscussionSettings>;
    lockedTools: Ref<Record<string, number>>;
    acceptsComments: Ref<boolean>;
    /** Identities the viewer may post as (AttributionResolver). */
    attributions: Ref<Attribution[]>;
    replyTo: Ref<number | null>;
    quote: Ref<{ commentId: number | string; text: string; authorName?: string } | null>;
    waitSeconds: Ref<number>;
    restore: Ref<{ parentId: number | null; html: string } | null>;
    openReply: (comment: Comment) => void;
    closeReply: () => void;
    report: (comment: Comment) => void;
    showRevisions: (comment: Comment) => void;
    confirmDelete: (comment: Comment) => void;
    replace: (comment: Comment) => void;
    /** Pins (or unpins) a top-level comment; moderators and the discussion owner. */
    pin: (comment: Comment) => Promise<void>;
    /** Withdraws an own comment that is still inside its undo window. */
    undo: (comment: Comment) => Promise<void>;
    posted: (payload: { item: Comment; message: string | null; bodyHtml: string }, parentId: number | null) => void;
    announce: (text: string) => void;
    setWait: (seconds: number) => void;
    jumpTo: (commentId: number | string) => void;
    /** Loads the next page of replies of a thread the tree cut at the reply cap. */
    loadReplies: (root: Comment) => Promise<void>;
    /** Per cut thread (root id): loading, error and the cursor of the next page. */
    threads: Ref<Record<string, ThreadState>>;
}

export interface ThreadState {
    loading: boolean;
    error: string;
    cursor: string | null;
    total: number;
}

export const RR_DISCUSSION: InjectionKey<DiscussionContext> = Symbol('rr-discussion');

export function useDiscussion(): DiscussionContext {
    const context = inject(RR_DISCUSSION, null);

    if (!context) {
        throw new Error('[ratings-and-reviews] <RrComment> must be rendered inside <RrDiscussion>.');
    }

    return context;
}

/** Replaces a comment anywhere in the tree (by id), keeping its replies when the update has none. */
export function replaceInTree(items: Comment[], updated: Comment): Comment[] {
    return items.map((item) => {
        if (item.id === updated.id) {
            return { ...updated, replies: updated.replies.length ? updated.replies : item.replies };
        }

        return item.replies.length ? { ...item, replies: replaceInTree(item.replies, updated) } : item;
    });
}

/** Removes a comment; when it has replies it stays as a placeholder (as the server does). */
export function removeFromTree(items: Comment[], id: number, placeholder: string): Comment[] {
    return items.flatMap((item) => {
        if (item.id === id) {
            return item.replies.length ? [{ ...item, isRemoved: true, removedLabel: placeholder, bodyHtml: '', author: null, poll: null, quote: null }] : [];
        }

        return [item.replies.length ? { ...item, replies: removeFromTree(item.replies, id, placeholder) } : item];
    });
}

/** Adds a reply under its parent (or a root comment on top). */
export function insertInTree(items: Comment[], comment: Comment, parentId: number | null): Comment[] {
    if (parentId === null) {
        return [comment, ...items];
    }

    return items.map((item) => {
        if (item.id === parentId) {
            return { ...item, replies: [...item.replies, comment] };
        }

        return item.replies.length ? { ...item, replies: insertInTree(item.replies, comment, parentId) } : item;
    });
}

/**
 * The last reply a thread already shows in the server's reply order (shallowest first, then
 * oldest): the deepest, then newest one. GET comments/{id}/replies?after= continues behind it.
 */
export function lastShownReply(root: Comment): Comment | null {
    const replies = flatten(root.replies);

    return replies.reduce<Comment | null>((last, reply) => {
        if (last === null) {
            return reply;
        }

        const key = (comment: Comment) => [comment.depth, comment.createdAt ?? '', comment.id] as const;
        const [a, b] = [key(reply), key(last)];

        return a[0] !== b[0] ? (a[0] > b[0] ? reply : last) : a[1] !== b[1] ? (a[1] > b[1] ? reply : last) : (a[2] > b[2] ? reply : last);
    }, null);
}

/**
 * Adds flat replies (GET comments/{id}/replies) to a thread: every reply goes under its
 * parent, children oldest first; replies already shown keep their state, and a reply whose
 * parent is not in the thread is left out.
 */
export function mergeReplies(root: Comment, incoming: Comment[]): Comment {
    const nodes = new Map<number, Comment>();

    for (const reply of [...flatten(root.replies), ...incoming]) {
        if (!nodes.has(reply.id)) {
            nodes.set(reply.id, { ...reply, replies: [] });
        }
    }

    const children = new Map<number, Comment[]>();

    for (const reply of nodes.values()) {
        const parent = reply.parentId ?? root.id;
        children.set(parent, [...(children.get(parent) ?? []), reply]);
    }

    const build = (parentId: number): Comment[] => (children.get(parentId) ?? [])
        .slice()
        .sort((a, b) => (a.createdAt ?? '').localeCompare(b.createdAt ?? '') || a.id - b.id)
        .map((reply) => ({ ...reply, replies: build(reply.id) }));

    return { ...root, replies: build(root.id) };
}

/** Comments in reading order (depth first). */
export function flatten(items: Comment[]): Comment[] {
    return items.flatMap((item) => [item, ...flatten(item.replies)]);
}
