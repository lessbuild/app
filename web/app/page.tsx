import { redirect } from 'next/navigation';

/** Until the public site moves over (slice 10), the root opens the app; signed-out people are sent to sign in. */
export default function Home() {
    redirect('/dashboard');
}
