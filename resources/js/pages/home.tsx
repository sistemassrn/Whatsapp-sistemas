import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';

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

            <main className="flex min-h-screen items-center justify-center bg-zinc-950 px-9 py-9 text-white">
                <section className="w-full max-w-md rounded-2xl border border-white/10 bg-zinc-900 p-6">
                    <h1 className="text-2xl font-semibold tracking-tight text-white">
                        Iniciar sesión
                    </h1>
                    <p className="my-4 text-base text-zinc-300">
                        Accedé con tus datos de NexoSRN.
                    </p>

                    <form onSubmit={submit} className="space-y-6">
                        <div>
                            <label
                                htmlFor="usuario"
                                className="text-base font-light text-zinc-200"
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
                                className={`mt-2 h-9.5 w-full rounded-lg border bg-zinc-950 px-4 text-sm text-white outline-none transition placeholder:text-zinc-500 focus:border-green-400 ${errors.usuario ? 'border-red-400' : 'border-zinc-700'}`}
                                autoComplete="username"
                            />

                            {errors.usuario && (
                                <p className="mt-2 text-sm text-red-600">
                                    {errors.usuario}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="password"
                                className="text-base font-light text-zinc-200"
                            >
                                Contraseña
                            </label>

                            <div className={`mt-2 flex h-9.5 items-center rounded-lg border bg-zinc-950 px-4 text-sm text-white transition focus-within:border-green-400 ${errors.password ? 'border-red-400' : 'border-zinc-700'}`}>
                                <input
                                    id="password"
                                    name="password"
                                    type={showPassword ? 'text' : 'password'}
                                    value={data.password}
                                    onChange={(event) =>
                                        setData('password', event.target.value)
                                    }
                                    className="min-w-0 flex-1 bg-transparent text-white outline-none"
                                    autoComplete="current-password"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword((current) => !current)}
                                    className="ml-3 text-zinc-400 transition hover:text-white"
                                    aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                                >
                                    {showPassword ? <Eye className="size-5" /> : <EyeOff className="size-5" />}
                                </button>
                            </div>

                            {errors.password && (
                                <p className="mt-2 text-sm text-red-600">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="mt-4! w-full rounded-lg border border-green-500/50 bg-green-500/15 px-4 py-1.5 text-md font-normal text-green-200 transition hover:bg-green-500/25 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? 'Ingresando...' : 'Ingresar'}
                        </button>
                    </form>
                </section>
            </main>
        </>
    );
}
