import { inject, type InjectionKey, type Ref } from 'vue';
import type { Comment, DiscussionSettings, SubjectRef } from '../types';

export interface DiscussionContext {
    subject: SubjectRef;
    settings: Ref<DiscussionSettings>;
    lockedTools: Ref<Record<string, number>>;
    acceptsComments: Ref<boolean>;
    replyTo: Ref<number | null>;
    waitSeconds: Ref<number>;
    restore: Ref<{ parentId: number | null; html: string } | null>;
    openReply: (comment: Comment) => void;
    closeReply: () => void;
    report: (comment: Comment) => void;
    showRevisions: (comment: Comment) => void;
    confirmDelete: (comment: Comment) => void;
    replace: (comment: Comment) => void;
    posted: (payload: { item: Comment; message: string | null; bodyHtml: string }, parentId: number | null) => void;
    announce: (text: string) => void;
    setWait: (seconds: number) => void;
    jumpTo: (commentId: number | string) => void;
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

/** Comments in reading order (depth first). */
export function flatten(items: Comment[]): Comment[] {
    return items.flatMap((item) => [item, ...flatten(item.replies)]);
}
