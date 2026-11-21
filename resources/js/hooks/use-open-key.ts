import { useState } from 'react';

/**
 * A key that changes each time a dialog opens and stays put while it closes. Keying the
 * dialog's form with it resets the form on every opening without unmounting it during the
 * close animation (which would flash an empty box).
 */
export function useOpenKey(open: boolean): number {
    const [state, setState] = useState({ open, key: 0 });

    if (open !== state.open) {
        const key = open ? state.key + 1 : state.key;
        setState({ open, key });

        return key;
    }

    return state.key;
}
