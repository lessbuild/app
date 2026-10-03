import type { ReactNode } from 'react';

/** Signed-in pages: each draws its own shell (it knows its project and service); create and edit screens open in the modal slot. */
export default function SignedInLayout({ children, modal }: { children: ReactNode; modal: ReactNode }) {
    return (
        <>
            {children}
            {modal}
        </>
    );
}
