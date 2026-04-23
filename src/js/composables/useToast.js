import { ElNotification } from 'element-plus';

/**
 * Standardized toast notifications wrapping ElNotification.
 * Consistent styling, positioning, and safe HTML handling.
 */
export function useToast() {
    function success(message, title = 'Success') {
        ElNotification({
            type: 'success',
            title,
            message,
            offset: 32,
            duration: 3000,
        });
    }

    function error(message, title = 'Error') {
        ElNotification({
            type: 'error',
            title,
            message,
            offset: 32,
            duration: 5000,
        });
    }

    function warning(message, title = 'Warning') {
        ElNotification({
            type: 'warning',
            title,
            message,
            offset: 32,
            duration: 4000,
        });
    }

    function info(message, title = 'Info') {
        ElNotification({
            type: 'info',
            title,
            message,
            offset: 32,
            duration: 3000,
        });
    }

    return { success, error, warning, info };
}
