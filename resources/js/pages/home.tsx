import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { ThemeToggle } from '../theme';

export default function Home() {
    const { data, setData, post, processing, errors } = useForm({
        usuario: '',
        password: '',
    });
    const [showPassword, setShowPassword] = useState(false);

    useEffect(() => {
        if (Object.keys(errors).length > 0) {
            toast.error('Hay errores en el formulario.');
        }
    }, [errors]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Iniciar sesión" />

            <main className="app-shell relative flex min-h-screen flex-col items-center justify-center px-9 py-9">
                <ThemeToggle className="absolute top-5 right-5" />
                <img src="/logo-srn.png" alt="SRN" className="flex mb-5 h-16 w-auto object-contain" />
                {/* <img src="/logo-srn.png" alt="SRN" className="mx-auto mb-5 h-16 w-auto object-contain" /> */}
                <section className="app-surface w-full max-w-md rounded-2xl border p-6">
                    <h1 className="text-2xl font-normal tracking-tight">
                        Iniciar sesión
                    </h1>
                    <p className="app-muted my-4 text-base">
                        Accedé con tus datos de NexoSRN.
                    </p>

                    <form onSubmit={submit} className="space-y-6">
                        <div>
                            <label
                                htmlFor="usuario"
                                className="text-base font-light"
                            >
                                Usuario
                            </label>

                            <input
                                id="usuario"
                                name="usuario"
                                type="text"
                                value={data.usuario}
                                onChange={(event) =>
                                    setData('usuario', event.target.value)
                                }
                                className={`app-input-control mt-2 h-9.5 w-full rounded-xl border px-4 text-sm outline-none transition ${errors.usuario ? 'border-(--app-danger)' : 'border-(--app-control-border) focus:border-(--app-focus)'}`}
                                autoComplete="username"
                            />

                            {errors.usuario && (
                                <p className="mt-2 text-sm text-(--app-danger)">
                                    {errors.usuario}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="password"
                                className="text-base font-light"
                            >
                                Contraseña
                            </label>

                            <div className={`app-input-shell mt-2 flex h-9.5 items-center px-4 text-sm transition ${errors.password ? 'border-(--app-danger)' : ''}`}>
                                <input
                                    id="password"
                                    name="password"
                                    type={showPassword ? 'text' : 'password'}
                                    value={data.password}
                                    onChange={(event) =>
                                        setData('password', event.target.value)
                                    }
                                    className="min-w-0 flex-1 bg-transparent text-(--app-control-text) outline-none"
                                    autoComplete="current-password"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword((current) => !current)}
                                    className="app-faint ml-3 transition hover:text-(--app-accent)"
                                    aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                                >
                                    {showPassword ? <Eye className="size-5" /> : <EyeOff className="size-5" />}
                                </button>
                            </div>

                            {errors.password && (
                                <p className="mt-2 text-sm text-(--app-danger)">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="app-button-primary mt-4! w-full rounded-xl border px-4 py-1.5 text-md font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? 'Ingresando...' : 'Ingresar'}
                        </button>
                    </form>
                </section>
            </main>
        </>
    );
}
