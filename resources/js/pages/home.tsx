import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Home() {
    const { data, setData, post, processing, errors } = useForm({
        usuario: '',
        password: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Inicio" />

            <main className="flex min-h-screen items-center justify-center bg-gray-50 px-6 py-12 text-black">
                <section className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8">

                    <h1 className=" text-3xl font-semibold tracking-tight text-black">
                        Iniciar sesión
                    </h1>
                    <p className="mt-2 text-sm text-gray-600">
                        Accedé con tu usuario de NexoSRN.
                    </p>

                    <form onSubmit={submit} className="mt-8 space-y-5">
                        <div>
                            <label
                                htmlFor="usuario"
                                className="text-sm font-medium text-black"
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
                                className="mt-2 w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-black outline-none transition focus:border-green-600"
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
                                className="text-sm font-medium text-black"
                            >
                                Contraseña
                            </label>

                            <input
                                id="password"
                                name="password"
                                type="password"
                                value={data.password}
                                onChange={(event) =>
                                    setData('password', event.target.value)
                                }
                                className="mt-2 w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-black outline-none transition focus:border-green-600"
                                autoComplete="current-password"
                            />

                            {errors.password && (
                                <p className="mt-2 text-sm text-red-600">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-xl bg-green-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? 'Ingresando...' : 'Ingresar'}
                        </button>
                    </form>
                </section>
            </main>
        </>
    );
}
