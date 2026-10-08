import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import UploadDetail, { type Upload } from '@/pages/upload/detail';

export default function UploaderUploadDetail({ upload }: { upload: Upload }) {
    return (
        <div className="flex flex-1 flex-col">
            <div className="mx-auto w-full max-w-6xl px-4 pt-3 sm:px-6">
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1.5 px-2 text-muted-foreground text-xs hover:text-foreground"
                >
                    <Link href="/uploader/uploads">
                        <ArrowLeft className="size-3.5" /> Back to my uploads
                    </Link>
                </Button>
            </div>
            <UploadDetail upload={upload} adminView={false} />
        </div>
    );
}

UploaderUploadDetail.layout = {
    breadcrumbs: [
        { title: 'My uploads', href: '/uploader/uploads' },
        { title: 'Upload details', href: '#' },
    ],
};
