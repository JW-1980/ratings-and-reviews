import { ref } from 'vue';

/**
 * A polite live region message. Setting the same text twice still announces it: the text
 * is cleared first and set on the next frame.
 */
export function useAnnouncer() {
    const message = ref('');

    function announce(text: string) {
        message.value = '';
        const set = () => {
            message.value = text;
        };

        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(set);
        } else {
            setTimeout(set, 0);
        }
    }

    return { message, announce };
}
