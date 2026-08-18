import UploadDetail, { type Upload } from '@/pages/upload/detail';

export default function AdminUploadDetail({ upload }: { upload: Upload }) {
    return <UploadDetail upload={upload} adminView />;
}

AdminUploadDetail.layout = {
    breadcrumbs: [
        { title: 'Receive logs', href: '/admin/uploads' },
        { title: 'Upload details', href: '#' },
    ],
};
