import { useToast } from './useToast';

/**
 * Native clipboard API wrapper replacing ClipboardJS/jQuery.
 */
export function useClipboard() {
    const toast = useToast();

    async function copy(text, successMessage = 'Copied to clipboard') {
        try {
            await navigator.clipboard.writeText(text);
            toast.success(successMessage);
            return true;
        } catch {
            // Fallback for older browsers or insecure contexts
            try {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                toast.success(successMessage);
                return true;
            } catch {
                toast.error('Failed to copy to clipboard');
                return false;
            }
        }
    }

    return { copy };
}
